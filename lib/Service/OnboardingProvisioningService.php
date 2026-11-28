<?php

/**
 * Grants a joining member the groups of their role, and takes a leaving
 * member's groups away, for a member onboarding or offboarding.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\Decidiq\Service\Pathway\MemberGroupChanges;
use OCA\Decidiq\Service\Pathway\MemberGroupPolicy;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Both actions run only when the secretariat confirms the step, and both
 * fail closed: when a group cannot be resolved or a write does not hold, the
 * step stays open, the error names the group, and nothing is reported as
 * done that was not checked afterwards. Groups come from the body's
 * `memberGroups`; groups outside it are never touched. Chair and signatory
 * scopes follow from the roster through the role-to-scope projection, so
 * this class never writes a scope group.
 *
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
class OnboardingProvisioningService {

	/**
	 * The onboarding schema.
	 *
	 * @var string
	 */
	public const ONBOARDING = 'member-onboarding';

	/**
	 * The offboarding schema.
	 *
	 * @var string
	 */
	public const OFFBOARDING = 'member-offboarding';

	/**
	 * The onboarding step that grants the groups.
	 *
	 * @var string
	 */
	public const STEP_ASSIGN = 'assign-groups';

	/**
	 * The offboarding step that takes the groups away.
	 *
	 * @var string
	 */
	public const STEP_REVOKE = 'revoke-groups';

	/**
	 * The admin setting listing the groups a body may map: comma separated group ids.
	 *
	 * A governance body record is edited by staff, not by an administrator, so
	 * the groups it maps are only provisioned or revoked when an administrator
	 * listed them here. Nothing listed means nothing is provisioned.
	 */
	public const ALLOWED_GROUPS_KEY = 'member_provisioning_groups';

	/**
	 * Groups that are never provisioned or revoked, whatever the setting says.
	 */
	public const PROTECTED_GROUPS = ['admin'];

	/**
	 * Per pathway schema: its groups step and the refusals when it is missing or done.
	 */
	private const STEP_TEXTS = [
		self::ONBOARDING  => [
			'step'     => self::STEP_ASSIGN,
			'notFound' => 'Onboarding not found.',
			'noStep'   => 'This onboarding has no step that assigns groups.',
			'done'     => 'The groups have already been assigned.',
		],
		self::OFFBOARDING => [
			'step'     => self::STEP_REVOKE,
			'notFound' => 'Offboarding not found.',
			'noStep'   => 'This offboarding has no step that revokes groups.',
			'done'     => 'The groups have already been revoked.',
		],
	];

	/**
	 * Reads and completes the checklist steps on a pathway record.
	 *
	 * @var PathwaySteps
	 */
	private readonly PathwaySteps $pathwaySteps;

	/**
	 * Which groups may be assigned or revoked.
	 *
	 * @var MemberGroupPolicy
	 */
	private readonly MemberGroupPolicy $groupPolicy;

	/**
	 * Adds to and removes from groups.
	 *
	 * @var MemberGroupChanges
	 */
	private readonly MemberGroupChanges $groupChanges;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store        The register's objects.
	 * @param IGroupManager       $groupManager The Nextcloud group manager.
	 * @param IUserManager        $userManager  The Nextcloud user manager.
	 * @param ITimeFactory        $time         The clock.
	 * @param LoggerInterface     $logger       The logger.
	 * @param IAppConfig|null     $appConfig    Holds the groups an administrator allows; null allows none.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		?IAppConfig $appConfig=null,
	) {
		$this->pathwaySteps = new PathwaySteps();
		$this->groupPolicy  = new MemberGroupPolicy(store: $store, appConfig: $appConfig);
		$this->groupChanges = new MemberGroupChanges(groupManager: $groupManager, logger: $logger);
	}//end __construct()

	/**
	 * The body an onboarding or offboarding belongs to, for the access check.
	 *
	 * @param string $schema The schema, self::ONBOARDING or self::OFFBOARDING.
	 * @param string $id     The record uuid.
	 *
	 * @return string|null Null when the record does not exist; empty when it names no body.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function bodyOf(string $schema, string $id): ?string {
		$record = $this->store->find(schema: $schema, uuid: $id);
		if ($record === null) {
			return null;
		}

		$key = 'body';
		if ($schema === self::ONBOARDING) {
			$key = 'targetBody';
		}

		return $this->pathwaySteps->relationId(value: ($record[$key] ?? null));
	}//end bodyOf()

	/**
	 * Add the joining member to the groups their role has on the body and
	 * complete the assign-groups step with the verified group list.
	 *
	 * @param string $onboardingId The onboarding uuid.
	 * @param string $actor        The uid confirming the step.
	 *
	 * @return PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function provision(string $onboardingId, string $actor): PathwayResult {
		$open = $this->openStep(schema: self::ONBOARDING, uuid: $onboardingId);
		if ($open instanceof PathwayResult) {
			return $open;
		}

		$user = $this->linkedUser(uid: (string) ($open['record']['ncAccount'] ?? ''));
		if ($user === null) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'Link the member to a Nextcloud account first.');
		}

		$groups = $this->provisionGroups(record: $open['record']);
		if ($groups instanceof PathwayResult) {
			return $groups;
		}

		$added      = $this->groupChanges->addToGroups(user: $user, groups: $groups);
		$unverified = $this->groupChanges->unverified(uid: $user->getUID(), groups: $groups);
		if ($unverified !== []) {
			$this->groupChanges->undo(user: $user, groups: $added);
			return new PathwayResult(
				outcome: PathwayResult::FAILED,
				message: sprintf('The member could not be added to: %s. No group was assigned.', implode(', ', $unverified))
			);
		}

		$granted = array_map(static fn (IGroup $group): string => $group->getGID(), $groups);
		$this->completeStep(
			open: $open,
			done: ['by' => $actor, 'note' => 'Groups: ' . implode(', ', $granted), 'reference' => implode(',', $granted)]
		);

		return new PathwayResult(outcome: PathwayResult::DONE, message: 'The groups have been assigned.', details: ['groups' => $granted]);
	}//end provision()

	/**
	 * Remove the leaving member from every group the body maps, end the
	 * membership on the offboarding's end date, and complete the
	 * revoke-groups step with the verified state.
	 *
	 * @param string $offboardingId The offboarding uuid.
	 * @param string $actor         The uid confirming the step.
	 *
	 * @return PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	public function revoke(string $offboardingId, string $actor): PathwayResult {
		$open = $this->openStep(schema: self::OFFBOARDING, uuid: $offboardingId);
		if ($open instanceof PathwayResult) {
			return $open;
		}

		$plan = $this->revokePlan(record: $open['record']);
		if ($plan instanceof PathwayResult) {
			return $plan;
		}

		$removed   = $this->groupChanges->removeFromGroups(user: $plan['user'], names: $plan['names']);
		$remaining = $this->groupChanges->stillIn(uid: $plan['user']->getUID(), names: $plan['names']);
		if ($remaining !== []) {
			return new PathwayResult(
				outcome: PathwayResult::FAILED,
				message: sprintf('The member is still in: %s. The step stays open.', implode(', ', $remaining))
			);
		}

		if ($this->endMembership(membershipId: $plan['membershipId'], endDate: $plan['endDate']) === false) {
			return new PathwayResult(
				outcome: PathwayResult::FAILED,
				message: 'The groups were revoked, but the membership could not be ended. The step stays open.'
			);
		}

		$note = 'Not in any group of this body any more.';
		if ($removed !== []) {
			$note = 'Removed from: ' . implode(', ', $removed) . '. ' . $note;
		}

		$this->completeStep(open: $open, done: ['by' => $actor, 'note' => $note]);

		return new PathwayResult(
			outcome: PathwayResult::DONE,
			message: 'The groups have been revoked and the membership ended.',
			details: ['removed' => $removed, 'endDate' => $plan['endDate']]
		);
	}//end revoke()

	/**
	 * The pathway record and its open groups step, or why there is none.
	 *
	 * @param string $schema The pathway schema: onboarding or offboarding.
	 * @param string $uuid   The pathway uuid.
	 *
	 * @return array{schema: string, uuid: string, record: array<string,mixed>, steps: list<array<string,mixed>>, index: int}|PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function openStep(string $schema, string $uuid): array|PathwayResult {
		$texts  = self::STEP_TEXTS[$schema];
		$record = $this->store->find(schema: $schema, uuid: $uuid);
		if ($record === null) {
			return new PathwayResult(outcome: PathwayResult::NOT_FOUND, message: $texts['notFound']);
		}

		$steps = $this->pathwaySteps->listOf(record: $record);
		$index = $this->pathwaySteps->indexOf(steps: $steps, stepType: $texts['step']);
		if ($index === null) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: $texts['noStep']);
		}

		if ($this->pathwaySteps->isCompleted(steps: $steps, index: $index) === true) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: $texts['done']);
		}

		return ['schema' => $schema, 'uuid' => $uuid, 'record' => $record, 'steps' => $steps, 'index' => $index];
	}//end openStep()

	/**
	 * Complete the open groups step of a pathway.
	 *
	 * @param array{schema: string, uuid: string, steps: list<array<string,mixed>>, index: int} $open The open step.
	 * @param array{by: string, note: string, reference?: string}                             $done Who completed it, and the note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function completeStep(array $open, array $done): void {
		$this->store->patch(
			schema: $open['schema'],
			data: [
				'steps' => $this->pathwaySteps->complete(
					steps: $open['steps'],
					index: $open['index'],
					completedBy: $done['by'],
					completedAt: $this->now(),
					note: $done['note'],
					reference: ($done['reference'] ?? null)
				),
			],
			uuid: $open['uuid']
		);
	}//end completeStep()

	/**
	 * The groups an onboarding's role maps to, all allowed and existing, or why not.
	 *
	 * @param array<string,mixed> $record The onboarding.
	 *
	 * @return list<IGroup>|PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function provisionGroups(array $record): array|PathwayResult {
		$role   = (string) ($record['targetRole'] ?? '');
		$mapped = $this->groupPolicy->mappedGroups(bodyId: $this->pathwaySteps->relationId(value: ($record['targetBody'] ?? null)));
		$names  = ($mapped[$role] ?? []);
		if ($names === []) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: sprintf('No groups are mapped for the role "%s" on this body.', $role));
		}

		$refused = $this->groupPolicy->refuseNotAllowed(names: $names, what: 'assigned');
		if ($refused !== null) {
			return $refused;
		}

		$resolved = $this->groupChanges->resolve(names: $names);
		if ($resolved['missing'] !== []) {
			return new PathwayResult(
				outcome: PathwayResult::REFUSED,
				message: sprintf('These groups do not exist: %s. No group was assigned.', implode(', ', $resolved['missing']))
			);
		}

		return $resolved['groups'];
	}//end provisionGroups()

	/**
	 * What an offboarding revokes: its end date, membership, account and the body's groups, or why it cannot.
	 *
	 * @param array<string,mixed> $record The offboarding.
	 *
	 * @return array{endDate: string, membershipId: string, user: IUser, names: list<string>}|PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function revokePlan(array $record): array|PathwayResult {
		$endDate = trim((string) ($record['endDate'] ?? ''));
		if ($endDate === '') {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'Set the end date of the offboarding first.');
		}

		$membershipId = $this->pathwaySteps->relationId(value: ($record['membership'] ?? null));
		if ($membershipId === '' || $this->store->find(schema: 'membership', uuid: $membershipId) === null) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'This offboarding names no membership to end.');
		}

		$user = $this->personUser(personId: $this->pathwaySteps->relationId(value: ($record['person'] ?? null)));
		if ($user === null) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'The member has no Nextcloud account to revoke.');
		}

		$names = $this->groupPolicy->allMappedGroups(bodyId: $this->pathwaySteps->relationId(value: ($record['body'] ?? null)));
		if ($names === []) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'No groups are mapped on this body, so access cannot be revoked and checked.');
		}

		return ($this->groupPolicy->refuseNotAllowed(names: $names, what: 'revoked') ?? [
			'endDate'      => $endDate,
			'membershipId' => $membershipId,
			'user'         => $user,
			'names'        => $names,
		]);
	}//end revokePlan()

	/**
	 * End the membership on the given date.
	 *
	 * @param string $membershipId The membership uuid.
	 * @param string $endDate      The end date.
	 *
	 * @return bool Whether the write held.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function endMembership(string $membershipId, string $endDate): bool {
		try {
			$this->store->patch(schema: 'membership', data: ['endDate' => $endDate], uuid: $membershipId);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: ending a membership on offboarding failed', ['exception' => $e]);
			return false;
		}
	}//end endMembership()

	/**
	 * The Nextcloud account an onboarding links, when it exists.
	 *
	 * @param string $uid The linked uid.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function linkedUser(string $uid): ?IUser {
		$uid = trim($uid);
		if ($uid === '') {
			return null;
		}

		return $this->userManager->get($uid);
	}//end linkedUser()

	/**
	 * The Nextcloud account of a person, when it exists.
	 *
	 * @param string $personId The person uuid.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function personUser(string $personId): ?IUser {
		if ($personId === '') {
			return null;
		}

		$person = $this->store->find(schema: 'person', uuid: $personId);
		return $this->linkedUser(uid: (string) ($person['nextcloudUserId'] ?? ''));
	}//end personUser()

	/**
	 * Now, as an ISO-8601 date-time.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * The groups an administrator allows member provisioning to assign or revoke.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function allowedGroups(): array {
		return $this->groupPolicy->allowedGroups();
	}//end allowedGroups()

}//end class
