<?php

/**
 * Stage Lapse Policy
 *
 * What a step's silence means once its due date has passed, and when its
 * actor's substitute is asked as well. Pure decisions: this works out what
 * should happen, and the sweep does it.
 *
 * SILENCE MEANS NOTHING UNLESS A STEP SAYS SO
 * --------------------------------------------
 * `hold` is the default and is what every stored route means today: the stage
 * stays active past its due date and nothing changes. An engine that advanced
 * routes on silence by default would produce approvals nobody gave, on routes
 * written before anybody had thought about it.
 *
 * AND A STAGE WITH NO DUE DATE NEVER LAPSES
 * ------------------------------------------
 * Whatever its `onSilence` says. A missing deadline is not a deadline that has
 * passed, and treating it as one would fire every policy at once the first time
 * the sweep ran.
 *
 * ESCALATION HAPPENS ONCE
 * -----------------------
 * A stage that lapsed to the manager and then lapses again holds. Otherwise a
 * quiet fortnight walks a decision up an entire hierarchy, and the last person
 * it reaches has no idea why it is theirs.
 *
 * THE SUBSTITUTE IS ASKED BEFORE THE DEADLINE
 * --------------------------------------------
 * Part-way through the window, not after it. A substitute asked once the term
 * has run is a substitute asked too late to help. Asking them does not remove
 * the original actor: both may act, and whoever acts first closes the stage.
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
use RuntimeException;

/**
 * Decides what a lapsed stage does, and when a substitute is asked.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
 */
final class StageLapsePolicy {
	/**
	 * Nothing happens. The default, and what every stored route means.
	 *
	 * @var string
	 */
	public const ON_SILENCE_HOLD = 'hold';

	/**
	 * The stage completes as approved and the route advances.
	 *
	 * @var string
	 */
	public const ON_SILENCE_APPROVE = 'approve';

	/**
	 * The stage completes as rejected and the route concludes.
	 *
	 * @var string
	 */
	public const ON_SILENCE_REFUSE = 'refuse';

	/**
	 * The stage is reassigned to the actor's manager, once.
	 *
	 * @var string
	 */
	public const ON_SILENCE_ESCALATE = 'escalate';

	/**
	 * What a step may declare its silence to mean.
	 *
	 * @var array<int, string>
	 */
	public const ON_SILENCE = [
		self::ON_SILENCE_HOLD,
		self::ON_SILENCE_APPROVE,
		self::ON_SILENCE_REFUSE,
		self::ON_SILENCE_ESCALATE,
	];

	/**
	 * Nothing to do.
	 *
	 * @var string
	 */
	public const EFFECT_NONE = 'none';

	/**
	 * Complete the stage and move on.
	 *
	 * @var string
	 */
	public const EFFECT_ADVANCE = 'advance';

	/**
	 * Complete the stage and conclude the route.
	 *
	 * @var string
	 */
	public const EFFECT_CONCLUDE = 'conclude';

	/**
	 * Reassign the stage.
	 *
	 * @var string
	 */
	public const EFFECT_REASSIGN = 'reassign';

	/**
	 * Refuse `approve` from anybody but an administrator.
	 *
	 * Silence that approves is a signature nobody gave. Whether that is
	 * acceptable is a decision for whoever runs the instance, not for whoever
	 * happens to be editing a route.
	 *
	 * @param string $onSilence The value being set.
	 * @param bool $isAdministrator Whether the person setting it is an administrator.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the value is unknown, or `approve` is set by somebody else.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function assertSettable(string $onSilence, bool $isAdministrator): void {
		if (in_array($onSilence, self::ON_SILENCE, true) === false) {
			throw new RuntimeException(
				sprintf('Unknown onSilence "%s"; expected one of %s.', $onSilence, implode(', ', self::ON_SILENCE))
			);
		}

		if ($onSilence === self::ON_SILENCE_APPROVE && $isAdministrator === false) {
			throw new RuntimeException(
				'Only an administrator can set a step to approve on silence. An approval nobody gave is a signature nobody gave.'
			);
		}
	}//end assertSettable()

	/**
	 * What should happen to a stage, given the clock.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array{effect: string, outcome: ?string, reason: string} What to do, and why.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
	 */
	public function effectFor(array $stage, DateTimeImmutable $now): array {
		if ((string)($stage['status'] ?? '') !== 'active') {
			// A stage the actor decided before the sweep ran is not silent, it
			// is finished.
			return $this->nothing('This stage is no longer active.');
		}

		if ((string)($stage['lapsedAt'] ?? '') !== '') {
			// Already lapsed once. Applying a policy twice is how running the
			// sweep after a failed run double-advances a route.
			return $this->nothing('A lapse has already been applied to this stage.');
		}

		$dueAt = trim((string)($stage['dueAt'] ?? ''));
		if ($dueAt === '') {
			// A missing deadline is not a deadline that has passed.
			return $this->nothing('This stage has no due date, so it cannot lapse.');
		}

		if ($this->hasPassed(instant: $dueAt, now: $now) === false) {
			return $this->nothing('This stage is not due yet.');
		}

		$onSilence = (string)($stage['onSilence'] ?? self::ON_SILENCE_HOLD);

		if ($onSilence === self::ON_SILENCE_ESCALATE && (string)($stage['actorResolvedBy'] ?? '') === ApprovalActorResolver::RULE_MANAGER_OF_ACTOR) {
			// Escalated once already. A quiet fortnight must not walk a decision
			// up an entire hierarchy to somebody with no idea why it is theirs.
			return $this->nothing('This stage has already been escalated once and now holds.');
		}

		return match ($onSilence) {
			self::ON_SILENCE_APPROVE => [
				'effect' => self::EFFECT_ADVANCE,
				'outcome' => 'approved',
				'reason' => 'The step declares that silence approves, and its due date has passed.',
			],
			self::ON_SILENCE_REFUSE => [
				'effect' => self::EFFECT_CONCLUDE,
				'outcome' => 'rejected',
				'reason' => 'The step declares that silence refuses, and its due date has passed.',
			],
			self::ON_SILENCE_ESCALATE => [
				'effect' => self::EFFECT_REASSIGN,
				'outcome' => null,
				'reason' => 'The step declares that silence escalates, and its due date has passed.',
			],
			default => $this->nothing('The step holds on silence, which is the default.'),
		};
	}//end effectFor()

	/**
	 * The action a lapse appends, so a stage that moved on its own can say who
	 * moved it and under which policy.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param array{effect: string, outcome: ?string, reason: string} $effect What the lapse did.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array<string, mixed> The action to append.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
	 */
	public function lapseAction(array $stage, array $effect, DateTimeImmutable $now): array {
		// The field names are the STAGE's own, not the ones a route step uses:
		// a stage keeps its subject under `decision`, its subject schema under
		// `note` and its step number under `sequence`. Reading `subject` and
		// `order` here is how a lapse row ends up with an empty subject and step
		// zero, which validates, stores, and is unreadable afterwards.
		return [
			'subject' => (string)($stage['decision'] ?? ($stage['subject'] ?? '')),
			'subjectSchema' => (string)($stage['note'] ?? ($stage['subjectSchema'] ?? '')),
			'step' => (int)($stage['sequence'] ?? ($stage['order'] ?? 0)),
			// `system` and not the actor's name: nobody signed this, and an
			// action carrying their name would read exactly like one they did.
			'actor' => 'system',
			'actorType' => 'system',
			'action' => 'lapsed',
			'state' => ($effect['outcome'] === 'rejected' ? 'refused' : 'granted'),
			'comment' => $effect['reason'],
			'onSilencePolicy' => (string)($stage['onSilence'] ?? self::ON_SILENCE_HOLD),
			'recordedAt' => $now->format(DateTimeImmutable::ATOM),
		];
	}//end lapseAction()

	/**
	 * Whether the substitute should be asked now, and whether they already were.
	 *
	 * @param array<string, mixed> $stage The stage, with its window and its ask point.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return bool True when the ask point has passed and nobody has asked yet.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function shouldAskSubstitute(array $stage, DateTimeImmutable $now): bool {
		if ((string)($stage['status'] ?? '') !== 'active') {
			return false;
		}

		if ((string)($stage['substituteAskedAt'] ?? '') !== '' || (string)($stage['substituteSearchedAt'] ?? '') !== '') {
			// Asked, or looked for and not found. Either way this is settled and
			// asking again every night would be a nightly mail to the same
			// person about the same stage.
			return false;
		}

		$fraction = ($stage['askSubstituteAfter'] ?? null);
		if (is_numeric($fraction) === false) {
			// Unset means no substitute is asked.
			return false;
		}

		$askPoint = $this->askPointFor($stage);

		return ($askPoint !== null && $askPoint <= $now);
	}//end shouldAskSubstitute()

	/**
	 * The instant at which the substitute is asked, a fraction of the way
	 * through the stage's window.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return DateTimeImmutable|null The instant, or null when the window is unknown.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function askPointFor(array $stage): ?DateTimeImmutable {
		$fraction = ($stage['askSubstituteAfter'] ?? null);
		$startedAt = trim((string)($stage['activatedAt'] ?? ($stage['startedAt'] ?? '')));
		$dueAt = trim((string)($stage['dueAt'] ?? ''));

		if (is_numeric($fraction) === false || $startedAt === '' || $dueAt === '') {
			return null;
		}

		$share = (float)$fraction;
		if ($share < 0.0 || $share > 1.0) {
			return null;
		}

		$start = $this->parse($startedAt);
		$due = $this->parse($dueAt);
		if ($start === null || $due === null || $due <= $start) {
			return null;
		}

		$window = ($due->getTimestamp() - $start->getTimestamp());
		$offset = (int)round(($window * $share));

		return $start->modify(sprintf('+%d seconds', $offset));
	}//end askPointFor()

	/**
	 * The decision to do nothing, with the reason.
	 *
	 * @param string $reason Why nothing happens.
	 *
	 * @return array{effect: string, outcome: ?string, reason: string} The decision.
	 */
	private function nothing(string $reason): array {
		return ['effect' => self::EFFECT_NONE, 'outcome' => null, 'reason' => $reason];
	}//end nothing()

	/**
	 * Whether an instant is in the past.
	 *
	 * @param string $instant The instant.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return bool True when it has passed.
	 */
	private function hasPassed(string $instant, DateTimeImmutable $now): bool {
		$parsed = $this->parse($instant);

		return ($parsed !== null && $parsed <= $now);
	}//end hasPassed()

	/**
	 * Parse an instant, answering null rather than throwing.
	 *
	 * An unparseable due date must not take the nightly sweep down with it: one
	 * bad row would then stop every other stage from lapsing, and nothing would
	 * say so.
	 *
	 * @param string $instant The instant.
	 *
	 * @return DateTimeImmutable|null The instant, or null.
	 */
	private function parse(string $instant): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable($instant);
		} catch (\Throwable $e) {
			return null;
		}
	}//end parse()
}//end class
