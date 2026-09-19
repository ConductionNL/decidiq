<?php

/**
 * Approval Stage Activator
 *
 * Turns a stage that is about to become live into a stage that knows who it is
 * for. A step that named a rule instead of a person is resolved HERE, at the
 * moment the stage activates, and the answer is written onto the stage.
 *
 * WHY AT ACTIVATION AND NOT AT INSTANTIATION
 * -------------------------------------------
 * A route instantiated in March and reaching its third step in September should
 * ask whoever manages that person in September. Resolving every step the day the
 * route starts asks March's manager, who may have left. Resolving at activation
 * also leaves the steps already signed alone: their actor was recorded when they
 * were asked, and a later reorganisation does not rewrite who signed.
 *
 * WHY A FAILURE HERE REFUSES RATHER THAN ASSIGNS NOBODY
 * ------------------------------------------------------
 * An unassigned step is a step anybody may take (REQ-AR-007). So falling back to
 * "no actor" when a manager cannot be found does not pause the route, it opens
 * it. The refusal is loud, visible the same minute, and fixable in the
 * organisation record.
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
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves a stage's actor rule when the stage becomes live.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012)
 */
class ApprovalStageActivator {
	/**
	 * The schema slug that holds the organisation's people.
	 *
	 * Read through OpenRegister like every other record this app touches. Not a
	 * service resolved out of humaniq's container and not an HTTP call to a
	 * sibling app: either would make decidiq unable to boot without that app,
	 * for a lookup that is one query (ADR-022, ADR-041, gate-27).
	 *
	 * @var string
	 */
	private const PERSON_SCHEMA = 'person';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads the organisation record.
	 * @param ApprovalActorResolver $resolver Turns a rule into one person, or refuses.
	 * @param IUserManager|null $userManager Answers whether a resolved person can sign
	 *        at all. Nullable so a unit test can leave the account check out; a
	 *        missing manager means the check is SKIPPED, never that it passed.
	 * @param LoggerInterface|null $logger Logger.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalActorResolver $resolver,
		private readonly ?IUserManager $userManager = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * The patch that makes a stage live.
	 *
	 * Always carries `status` and `activatedAt`. Carries the resolved actor as
	 * well when the stage names a rule.
	 *
	 * @param array<string, mixed> $stage The stage about to activate.
	 * @param string $subjectOwner Who owns the subject the route travels.
	 * @param DateTimeImmutable|null $now The clock; the real one when null.
	 *
	 * @return array<string, mixed> The fields to write.
	 *
	 * @throws \RuntimeException When the stage names a rule that cannot be resolved.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013)
	 */
	public function activationPatch(array $stage, string $subjectOwner = '', ?DateTimeImmutable $now = null): array {
		$stampedAt = ($now ?? new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		$patch = ['status' => 'active', 'activatedAt' => $stampedAt];

		$rule = trim((string)($stage['actorRule'] ?? ''));
		if ($rule === '') {
			return $patch;
		}

		$resolved = $this->resolver->resolve(
			step: [
				'actor' => '',
				'actorRule' => $rule,
				'actorRuleSubject' => (string)($stage['actorRuleSubject'] ?? ''),
			],
			people: $this->people(),
			subjectOwner: $subjectOwner,
			hasAccount: $this->accountCheck(),
			resolvedAt: $stampedAt,
		);

		$patch['assignedPerson'] = $resolved['actor'];
		$patch['actorResolvedBy'] = $resolved['actorResolvedBy'];
		$patch['actorResolvedAt'] = $resolved['actorResolvedAt'];
		$patch['decisionMakerType'] = 'person';

		return $patch;
	}//end activationPatch()

	/**
	 * The substitute of a person, or null when the record does not name exactly
	 * one.
	 *
	 * @param string $person Whose substitute to look for.
	 *
	 * @return string|null The substitute.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function substituteOf(string $person): ?string {
		if (trim($person) === '') {
			return null;
		}

		return $this->resolver->substituteOf(people: $this->people(), person: $person);
	}//end substituteOf()

	/**
	 * The manager of a person, or null when the record does not name exactly one.
	 *
	 * Used by escalation, which must not take the route down when it cannot find
	 * a manager: a failed escalation holds the stage, and holding is a state the
	 * sweep already knows how to describe.
	 *
	 * @param string $person Whose manager to look for.
	 *
	 * @return string|null The manager.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function managerOf(string $person): ?string {
		if (trim($person) === '') {
			return null;
		}

		try {
			$resolved = $this->resolver->resolve(
				step: [
					'actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
					'actorRuleSubject' => $person,
				],
				people: $this->people(),
				hasAccount: $this->accountCheck(),
			);
		} catch (\Throwable $e) {
			$this->logger?->info(
				'Decidiq: no manager resolved for an escalating approval stage',
				['person' => $person, 'reason' => $e->getMessage()]
			);
			return null;
		}

		return $resolved['actor'];
	}//end managerOf()

	/**
	 * The organisation's people, or an empty list when no schema holds them.
	 *
	 * An empty list is not "everybody": the resolver refuses on it, by name, so
	 * an instance with no organisation record cannot quietly produce a stage
	 * anybody may sign.
	 *
	 * @return array<int, array<string, mixed>> The person records.
	 */
	private function people(): array {
		try {
			return $this->store->findAll(schema: self::PERSON_SCHEMA, filters: []);
		} catch (\Throwable $e) {
			$this->logger?->warning(
				'Decidiq: could not read the organisation record for an actor rule',
				['reason' => $e->getMessage()]
			);
			return [];
		}
	}//end people()

	/**
	 * Whether a resolved person can sign on this instance.
	 *
	 * @return callable(string): bool|null The check, or null when there is no user manager.
	 */
	private function accountCheck(): ?callable {
		if ($this->userManager === null) {
			return null;
		}

		return fn (string $uid): bool => $this->userManager->userExists($uid);
	}//end accountCheck()
}//end class
