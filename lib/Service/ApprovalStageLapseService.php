<?php

/**
 * Approval Stage Lapse Service
 *
 * The sweep behind a declared silence. Once a night it looks at every stage
 * that is still waiting, works out what that stage said its own silence means,
 * and does it.
 *
 * WHAT SILENCE MEANS, IN ONE PLACE
 * ---------------------------------
 * Nothing, unless the step said otherwise. `hold` is the default and is what
 * every route written before this change means: the stage stays active past its
 * due date, it renders overdue, and a person still has to act. The three other
 * meanings are declared per step: `approve` advances, `refuse` concludes,
 * `escalate` asks the actor's manager once.
 *
 * WHO IS TOLD, AND WHEN
 * ----------------------
 * Part-way through the window, at the fraction the step declared, the actor's
 * substitute is asked as well and both of them are told. At the deadline, if a
 * policy fires, the actor is told what their silence was taken to mean and,
 * when it escalated, the manager is told the stage is now theirs. Nothing here
 * is silent about being silent: every lapse writes an ApprovalAction that names
 * the policy, so the record says why a step moved without anybody signing it.
 *
 * WHY IT IS ONE SWEEP AND NOT A TIMER PER STAGE
 * -----------------------------------------------
 * A timer per stage is a promise to fire exactly once, which nothing in a cron
 * environment can keep. A sweep that is safe to run twice is one that can be
 * re-run after a failure, and the `lapsedAt` stamp is what makes it safe: it is
 * written with the change, and a stage that carries one is skipped.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-016, REQ-AR-017)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Applies a lapsed stage's declared meaning, once.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
 */
class ApprovalStageLapseService {
	/**
	 * The stage schema.
	 *
	 * @var string
	 */
	private const STAGE_SCHEMA = 'decision-stage';

	/**
	 * The action schema.
	 *
	 * @var string
	 */
	private const ACTION_SCHEMA = 'approval-action';

	/**
	 * The notification event type a lapse and a substitute ask are dispatched
	 * under.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'approval-stage-lapse';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes stages and actions.
	 * @param StageLapsePolicy $policy Works out what should happen.
	 * @param ApprovalStageActivator $activator Resolves a manager or a substitute.
	 * @param LoggerInterface $logger Logger.
	 * @param NotificationPreferenceService|null $notifier Tells the people
	 *        involved. Nullable: whether anybody is NOTIFIED must never decide
	 *        whether the route moved, so a missing notifier is logged and the
	 *        sweep carries on.
	 * @param IL10N|null $l10n Translates what the people involved are told. The
	 *        sweep runs out of cron with no user session, so a missing
	 *        translator falls back to the source string rather than throwing.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly StageLapsePolicy $policy,
		private readonly ApprovalStageActivator $activator,
		private readonly LoggerInterface $logger,
		private readonly ?NotificationPreferenceService $notifier = null,
		private readonly ?IL10N $l10n = null,
	) {
	}//end __construct()

	/**
	 * Translate, falling back to the source string.
	 *
	 * @param string $text The source string.
	 *
	 * @return string The translation.
	 */
	private function t(string $text): string {
		return ($this->l10n?->t($text) ?? $text);
	}//end t()

	/**
	 * Sweep every waiting stage.
	 *
	 * @param DateTimeImmutable|null $now The clock; the real one when null.
	 *
	 * @return array{lapsed: int, substitutesAsked: int} What the sweep did.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
	 */
	public function sweep(?DateTimeImmutable $now = null): array {
		$clock = ($now ?? new DateTimeImmutable());
		$lapsed = 0;
		$asked = 0;

		foreach ($this->activeStages() as $stage) {
			try {
				if ($this->askSubstituteIfDue(stage: $stage, now: $clock) === true) {
					$asked++;
				}

				if ($this->applyLapse(stage: $stage, now: $clock) === true) {
					$lapsed++;
				}
			} catch (\Throwable $e) {
				// One unusable stage must not stop every other stage from
				// lapsing, and nothing would say so if it did.
				$this->logger->warning(
					'Decidiq: an approval stage could not be swept',
					['stage' => (string)($stage['id'] ?? ''), 'reason' => $e->getMessage()]
				);
			}
		}

		return ['lapsed' => $lapsed, 'substitutesAsked' => $asked];
	}//end sweep()

	/**
	 * Ask the actor's substitute when the declared point in the window has
	 * passed.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return bool True when a substitute was asked.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	private function askSubstituteIfDue(array $stage, DateTimeImmutable $now): bool {
		if ($this->policy->shouldAskSubstitute(stage: $stage, now: $now) === false) {
			return false;
		}

		$actor = (string)($stage['assignedPerson'] ?? '');
		$substitute = $this->activator->substituteOf($actor);
		$uuid = (string)($stage['id'] ?? '');

		if ($substitute === null) {
			// A note, not a refusal. Nobody's deadline changes and the original
			// actor is still the one being waited on.
			$this->store->patch(
				schema: self::STAGE_SCHEMA,
				data: ['substituteSearchedAt' => $now->format(DateTimeImmutable::ATOM)],
				uuid: $uuid,
			);
			$this->tell(
				$actor,
				$this->t('No stand-in was found for your sign-off'),
				$this->t('Nobody is recorded as your substitute, so this sign-off is still waiting on you. Its deadline has not moved.')
			);
			return false;
		}

		$this->store->patch(
			schema: self::STAGE_SCHEMA,
			data: [
				'substituteActor' => $substitute,
				'substituteAskedAt' => $now->format(DateTimeImmutable::ATOM),
			],
			uuid: $uuid,
		);

		// BOTH are told, because both may act. Telling only the substitute
		// reads to the original actor as if the step had been taken off them.
		$this->tell(
			$substitute,
			$this->t('A sign-off is waiting, on behalf of a colleague'),
			$this->t('You have been asked to stand in on a sign-off that is part-way through its term. Whoever acts first closes it.')
		);
		$this->tell(
			$actor,
			$this->t('Your stand-in has been asked as well'),
			$this->t('This sign-off is part-way through its term, so your substitute has been asked too. You can still sign it yourself.')
		);

		return true;
	}//end askSubstituteIfDue()

	/**
	 * Apply a stage's declared silence, once.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return bool True when the stage changed.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
	 */
	private function applyLapse(array $stage, DateTimeImmutable $now): bool {
		$effect = $this->policy->effectFor(stage: $stage, now: $now);
		if ($effect['effect'] === StageLapsePolicy::EFFECT_NONE) {
			return false;
		}

		$uuid = (string)($stage['id'] ?? '');
		$at = $now->format(DateTimeImmutable::ATOM);
		$actor = (string)($stage['assignedPerson'] ?? '');

		if ($effect['effect'] === StageLapsePolicy::EFFECT_REASSIGN) {
			$manager = $this->activator->managerOf($actor);
			if ($manager === null) {
				// Escalation with nobody to escalate to holds. It does not
				// advance and it does not conclude: both would turn a missing
				// line in the organisation record into a decision.
				$this->logger->info(
					'Decidiq: an approval stage could not escalate and holds',
					['stage' => $uuid, 'actor' => $actor]
				);
				return false;
			}

			$this->store->patch(
				schema: self::STAGE_SCHEMA,
				data: [
					'assignedPerson' => $manager,
					'decisionMakerType' => 'person',
					'actorResolvedBy' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
					'actorResolvedAt' => $at,
					'activatedAt' => $at,
					'dueAt' => $this->restartedWindow(stage: $stage, now: $now),
					'lapsedAt' => $at,
					// The stand-in fields belong to the person who has just been
					// replaced; carrying them onto the manager would ask their
					// predecessor's substitute.
					'substituteActor' => '',
					'substituteAskedAt' => '',
					'substituteSearchedAt' => '',
				],
				uuid: $uuid,
			);

			$this->recordLapse(stage: $stage, effect: $effect, now: $now);
			$this->tell(
				$actor,
				$this->t('Your sign-off has moved up'),
				$this->t('The term on this sign-off passed without an answer, and the step declares that silence escalates, so it is now with your manager.')
			);
			$this->tell(
				$manager,
				$this->t('A sign-off has escalated to you'),
				$this->t('A step your colleague was asked to sign passed its term without an answer. The step declares that silence escalates, so it is now yours, with a fresh term.')
			);

			return true;
		}

		$this->store->patch(
			schema: self::STAGE_SCHEMA,
			data: [
				'status' => 'decided',
				'outcome' => $effect['outcome'],
				'decidedAt' => $at,
				'lapsedAt' => $at,
			],
			uuid: $uuid,
		);

		$this->recordLapse(stage: $stage, effect: $effect, now: $now);

		if ($effect['effect'] === StageLapsePolicy::EFFECT_ADVANCE) {
			$this->activateNext(stage: $stage, now: $now);
			$this->tell(
				$actor,
				$this->t('A sign-off passed its term and was taken as approved'),
				$this->t('The term on this sign-off passed without an answer. The step declares that silence approves, so it has been recorded as approved by the system and the next step is now live.')
			);
			return true;
		}

		$this->tell(
			$actor,
			$this->t('A sign-off passed its term and was taken as refused'),
			$this->t('The term on this sign-off passed without an answer. The step declares that silence refuses, so the route has been concluded as rejected.')
		);

		return true;
	}//end applyLapse()

	/**
	 * Make the next pending step of the same subject live.
	 *
	 * @param array<string, mixed> $stage The stage that just completed.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	private function activateNext(array $stage, DateTimeImmutable $now): void {
		$subject = (string)($stage['decision'] ?? '');
		if ($subject === '') {
			return;
		}

		$sequence = (int)($stage['sequence'] ?? 0);
		$siblings = $this->store->findAll(schema: self::STAGE_SCHEMA, filters: ['decision' => $subject]);
		usort($siblings, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		$next = null;
		foreach ($siblings as $sibling) {
			if ((int)$sibling['sequence'] === $sequence && (string)($sibling['status'] ?? '') === 'active') {
				// A parallel sibling at the same step is still signing, so the
				// group is not done and nothing after it becomes live.
				return;
			}

			if ($next === null && (int)$sibling['sequence'] > $sequence && (string)($sibling['status'] ?? '') === 'pending') {
				$next = (int)$sibling['sequence'];
			}
		}

		if ($next === null) {
			return;
		}

		foreach ($siblings as $sibling) {
			if ((int)$sibling['sequence'] !== $next || (string)($sibling['status'] ?? '') !== 'pending') {
				continue;
			}

			$this->store->patch(
				schema: self::STAGE_SCHEMA,
				data: $this->activator->activationPatch(stage: $sibling, now: $now),
				uuid: (string)$sibling['id'],
			);
		}
	}//end activateNext()

	/**
	 * Append the action that says the step moved, and under which policy.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param array{effect: string, outcome: ?string, reason: string} $effect What the lapse did.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
	 */
	private function recordLapse(array $stage, array $effect, DateTimeImmutable $now): void {
		$this->store->save(
			schema: self::ACTION_SCHEMA,
			object: $this->policy->lapseAction(stage: $stage, effect: $effect, now: $now),
		);
	}//end recordLapse()

	/**
	 * A fresh term for an escalated stage, as long as the one it had.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return string The new due date, as an ISO-8601 instant.
	 */
	private function restartedWindow(array $stage, DateTimeImmutable $now): string {
		$default = $now->modify('+7 days');

		$startedAt = trim((string)($stage['activatedAt'] ?? ''));
		$dueAt = trim((string)($stage['dueAt'] ?? ''));
		if ($startedAt === '' || $dueAt === '') {
			return $default->format(DateTimeImmutable::ATOM);
		}

		try {
			$start = new DateTimeImmutable($startedAt);
			$due = new DateTimeImmutable($dueAt);
		} catch (\Throwable $e) {
			return $default->format(DateTimeImmutable::ATOM);
		}

		$window = ($due->getTimestamp() - $start->getTimestamp());
		if ($window <= 0) {
			return $default->format(DateTimeImmutable::ATOM);
		}

		return $now->modify(sprintf('+%d seconds', $window))->format(DateTimeImmutable::ATOM);
	}//end restartedWindow()

	/**
	 * Every stage still waiting.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function activeStages(): array {
		try {
			return $this->store->findAll(schema: self::STAGE_SCHEMA, filters: ['status' => 'active']);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not read the approval stages for the lapse sweep',
				['reason' => $e->getMessage()]
			);
			return [];
		}
	}//end activeStages()

	/**
	 * Tell somebody, without letting the telling decide whether the route moved.
	 *
	 * @param string $person Who to tell.
	 * @param string $title The title.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function tell(string $person, string $title, string $message): void {
		if ($person === '' || $this->notifier === null) {
			return;
		}

		try {
			$this->notifier->dispatch(
				personId: $person,
				eventType: self::EVENT_TYPE,
				title: $title,
				message: $message,
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: an approval lapse notification was not delivered',
				['person' => $person, 'reason' => $e->getMessage()]
			);
		}
	}//end tell()
}//end class
