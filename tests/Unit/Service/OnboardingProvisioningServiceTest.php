<?php

/**
 * Unit tests for OnboardingProvisioningService: granting a joining member the
 * groups of their role and revoking a leaving member's groups, fail closed.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Service\OnboardingProvisioningService;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OnboardingProvisioningService.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
#[CoversClass(OnboardingProvisioningService::class)]
#[UsesClass(PathwayResult::class)]
#[UsesClass(PathwaySteps::class)]
class OnboardingProvisioningServiceTest extends TestCase {

	/**
	 * The register's objects.
	 *
	 * @var RegisterObjectStore&MockObject
	 */
	private RegisterObjectStore&MockObject $store;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * The member's account.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * The service under test.
	 *
	 * @var OnboardingProvisioningService
	 */
	private OnboardingProvisioningService $service;

	/**
	 * Records by schema and uuid.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	private array $records = [];

	/**
	 * Build the service with a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store        = $this->createMock(RegisterObjectStore::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager  = $this->createMock(IUserManager::class);
		$this->user         = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('sem');
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'sem') ? $this->user : null);

		$this->store->method('find')->willReturnCallback(
			fn (string $schema, string $uuid): ?array => ($this->records[$schema][$uuid] ?? null)
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));

		$this->service = new OnboardingProvisioningService(
			store: $this->store,
			groupManager: $this->groupManager,
			userManager: $this->userManager,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A joining member is added to their role's groups, and the step records
	 * the verified list while every other step is carried forward unchanged.
	 *
	 * @return void
	 */
	public function testProvisionAddsTheRoleGroupsAndCompletesOnlyThatStep(): void {
		$this->givenOnboarding();
		$this->givenBody(mapping: ['member' => ['council', 'readers'], 'chair' => ['presidium']]);
		$council = $this->group(gid: 'council', member: false);
		$readers = $this->group(gid: 'readers', member: false);
		$this->givenGroups(['council' => $council, 'readers' => $readers]);
		$council->expects($this->once())->method('addUser')->with($this->user);
		$readers->expects($this->once())->method('addUser')->with($this->user);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$patched = null;
		$this->store->expects($this->once())->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid) use (&$patched): array {
				$this->assertSame('member-onboarding', $schema);
				$this->assertSame('onb-1', $uuid);
				$patched = $data['steps'];
				return [];
			}
		);

		$result = $this->service->provision(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertTrue($result->isDone());
		$this->assertSame(['council', 'readers'], $result->details['groups']);
		$this->assertSame($this->steps()[0], $patched[0]);
		$this->assertSame($this->steps()[2], $patched[2]);
		$this->assertSame('completed', $patched[1]['status']);
		$this->assertSame('griffier', $patched[1]['completedBy']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $patched[1]['completedAt']);
		$this->assertSame('Groups: council, readers', $patched[1]['note']);
		$this->assertSame('council,readers', $patched[1]['reference']);
	}//end testProvisionAddsTheRoleGroupsAndCompletesOnlyThatStep()

	/**
	 * A group the member is already in is verified but not written again.
	 *
	 * @return void
	 */
	public function testProvisionSkipsTheWriteForAGroupTheMemberIsAlreadyIn(): void {
		$this->givenOnboarding();
		$this->givenBody(mapping: ['member' => ['council']]);
		$council = $this->group(gid: 'council', member: true);
		$this->givenGroups(['council' => $council]);
		$council->expects($this->never())->method('addUser');
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->store->expects($this->once())->method('patch');

		$this->assertTrue($this->service->provision(onboardingId: 'onb-1', actor: 'griffier')->isDone());
	}//end testProvisionSkipsTheWriteForAGroupTheMemberIsAlreadyIn()

	/**
	 * A role with no mapped groups is refused by name and nothing is written.
	 *
	 * @return void
	 */
	public function testProvisionRefusesARoleWithoutMappedGroups(): void {
		$this->givenOnboarding();
		$this->givenBody(mapping: ['chair' => ['presidium']]);
		$this->groupManager->expects($this->never())->method('get');
		$this->store->expects($this->never())->method('patch');

		$result = $this->service->provision(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertSame('refused', $result->outcome);
		$this->assertSame('No groups are mapped for the role "member" on this body.', $result->message);
		$this->assertSame(422, $result->httpStatus());
	}//end testProvisionRefusesARoleWithoutMappedGroups()

	/**
	 * A mapped group that does not exist is named, and no group is assigned,
	 * not even the ones that do exist.
	 *
	 * @return void
	 */
	public function testProvisionRefusesAMissingGroupBeforeWritingAny(): void {
		$this->givenOnboarding();
		$this->givenBody(mapping: ['member' => ['council', 'ghost']]);
		$council = $this->group(gid: 'council', member: false);
		$this->givenGroups(['council' => $council]);
		$council->expects($this->never())->method('addUser');
		$this->store->expects($this->never())->method('patch');

		$result = $this->service->provision(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertSame('refused', $result->outcome);
		$this->assertSame('These groups do not exist: ghost. No group was assigned.', $result->message);
	}//end testProvisionRefusesAMissingGroupBeforeWritingAny()

	/**
	 * A write that does not hold leaves the step open, names the group, and
	 * takes back the groups this run did add: no partial success.
	 *
	 * @return void
	 */
	public function testProvisionFailsClosedAndUndoesWhenAWriteDoesNotHold(): void {
		$this->givenOnboarding();
		$this->givenBody(mapping: ['member' => ['council', 'readers']]);
		$council = $this->group(gid: 'council', member: false);
		$readers = $this->group(gid: 'readers', member: false);
		$this->givenGroups(['council' => $council, 'readers' => $readers]);
		$readers->method('addUser')->willThrowException(new \RuntimeException('backend is read-only'));
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($gid === 'council')
		);
		$council->expects($this->once())->method('removeUser')->with($this->user);
		$readers->expects($this->never())->method('removeUser');
		$this->store->expects($this->never())->method('patch');

		$result = $this->service->provision(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertSame('failed', $result->outcome);
		$this->assertSame('The member could not be added to: readers. No group was assigned.', $result->message);
		$this->assertFalse($result->isDone());
	}//end testProvisionFailsClosedAndUndoesWhenAWriteDoesNotHold()

	/**
	 * Without a linked account there is nobody to add.
	 *
	 * @return void
	 */
	public function testProvisionRefusesWithoutALinkedAccount(): void {
		$this->givenOnboarding(overrides: ['ncAccount' => '']);
		$this->store->expects($this->never())->method('patch');

		$result = $this->service->provision(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertSame('Link the member to a Nextcloud account first.', $result->message);
	}//end testProvisionRefusesWithoutALinkedAccount()

	/**
	 * A completed step is not run twice.
	 *
	 * @return void
	 */
	public function testProvisionRefusesACompletedStep(): void {
		$steps              = $this->steps();
		$steps[1]['status'] = 'completed';
		$this->givenOnboarding(overrides: ['steps' => $steps]);
		$this->groupManager->expects($this->never())->method('get');

		$result = $this->service->provision(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertSame('The groups have already been assigned.', $result->message);
	}//end testProvisionRefusesACompletedStep()

	/**
	 * An unknown onboarding answers not found.
	 *
	 * @return void
	 */
	public function testProvisionAnswersNotFoundForAnUnknownOnboarding(): void {
		$result = $this->service->provision(onboardingId: 'nope', actor: 'griffier');

		$this->assertSame('not-found', $result->outcome);
		$this->assertSame(404, $result->httpStatus());
	}//end testProvisionAnswersNotFoundForAnUnknownOnboarding()

	/**
	 * Revocation removes the member from every group the body maps, leaves
	 * other groups alone, ends the membership on the end date and records
	 * the verified state on the step.
	 *
	 * @return void
	 */
	public function testRevokeRemovesTheBodyGroupsEndsTheMembershipAndCompletesTheStep(): void {
		$this->givenOffboarding();
		$this->givenBody(mapping: ['member' => ['council'], 'chair' => ['council', 'presidium']]);
		$council   = $this->group(gid: 'council', member: true);
		$presidium = $this->group(gid: 'presidium', member: false);
		$this->givenGroups(['council' => $council, 'presidium' => $presidium]);
		$council->expects($this->once())->method('removeUser')->with($this->user);
		$presidium->expects($this->never())->method('removeUser');
		$this->groupManager->method('isInGroup')->willReturn(false);

		$writes = [];
		$this->store->expects($this->exactly(2))->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid) use (&$writes): array {
				$writes[] = [$schema, $data, $uuid];
				return [];
			}
		);

		$result = $this->service->revoke(offboardingId: 'off-1', actor: 'griffier');

		$this->assertTrue($result->isDone());
		$this->assertSame(['council'], $result->details['removed']);
		$this->assertSame(['membership', ['endDate' => '2026-11-30'], 'mem-1'], $writes[0]);
		$this->assertSame('member-offboarding', $writes[1][0]);
		$step = $writes[1][1]['steps'][1];
		$this->assertSame('completed', $step['status']);
		$this->assertSame('Removed from: council. Not in any group of this body any more.', $step['note']);
		$this->assertSame($this->offboardingSteps()[0], $writes[1][1]['steps'][0]);
	}//end testRevokeRemovesTheBodyGroupsEndsTheMembershipAndCompletesTheStep()

	/**
	 * When the member is still in a body group afterwards, nothing is
	 * recorded as revoked and the membership is not ended.
	 *
	 * @return void
	 */
	public function testRevokeFailsClosedWhileTheMemberIsStillInAGroup(): void {
		$this->givenOffboarding();
		$this->givenBody(mapping: ['member' => ['council']]);
		$council = $this->group(gid: 'council', member: true);
		$council->method('removeUser')->willThrowException(new \RuntimeException('read-only backend'));
		$this->givenGroups(['council' => $council]);
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->store->expects($this->never())->method('patch');

		$result = $this->service->revoke(offboardingId: 'off-1', actor: 'griffier');

		$this->assertSame('failed', $result->outcome);
		$this->assertSame('The member is still in: council. The step stays open.', $result->message);
	}//end testRevokeFailsClosedWhileTheMemberIsStillInAGroup()

	/**
	 * A membership that cannot be ended keeps the step open.
	 *
	 * @return void
	 */
	public function testRevokeKeepsTheStepOpenWhenTheMembershipCannotBeEnded(): void {
		$this->givenOffboarding();
		$this->givenBody(mapping: ['member' => ['council']]);
		$this->givenGroups(['council' => $this->group(gid: 'council', member: false)]);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->store->expects($this->once())->method('patch')
			->with('membership', ['endDate' => '2026-11-30'], 'mem-1')
			->willThrowException(new \RuntimeException('locked'));

		$result = $this->service->revoke(offboardingId: 'off-1', actor: 'griffier');

		$this->assertSame('failed', $result->outcome);
		$this->assertSame('The groups were revoked, but the membership could not be ended. The step stays open.', $result->message);
	}//end testRevokeKeepsTheStepOpenWhenTheMembershipCannotBeEnded()

	/**
	 * Without an end date there is no date to end the membership on.
	 *
	 * @return void
	 */
	public function testRevokeRefusesWithoutAnEndDate(): void {
		$this->givenOffboarding(overrides: ['endDate' => '']);
		$this->groupManager->expects($this->never())->method('get');

		$this->assertSame(
			'Set the end date of the offboarding first.',
			$this->service->revoke(offboardingId: 'off-1', actor: 'griffier')->message
		);
	}//end testRevokeRefusesWithoutAnEndDate()

	/**
	 * A body without mapped groups cannot be checked, so revocation refuses.
	 *
	 * @return void
	 */
	public function testRevokeRefusesWhenTheBodyMapsNoGroups(): void {
		$this->givenOffboarding();
		$this->givenBody(mapping: []);
		$this->store->expects($this->never())->method('patch');

		$this->assertSame(
			'No groups are mapped on this body, so access cannot be revoked and checked.',
			$this->service->revoke(offboardingId: 'off-1', actor: 'griffier')->message
		);
	}//end testRevokeRefusesWhenTheBodyMapsNoGroups()

	/**
	 * The body of a record comes from targetBody on an onboarding and body
	 * on an offboarding; an unknown record has none.
	 *
	 * @return void
	 */
	public function testBodyOfReadsTheRightRelation(): void {
		$this->givenOnboarding();
		$this->givenOffboarding();

		$this->assertSame('body-1', $this->service->bodyOf(schema: 'member-onboarding', id: 'onb-1'));
		$this->assertSame('body-1', $this->service->bodyOf(schema: 'member-offboarding', id: 'off-1'));
		$this->assertNull($this->service->bodyOf(schema: 'member-onboarding', id: 'nope'));
	}//end testBodyOfReadsTheRightRelation()

	/**
	 * The onboarding's three steps.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function steps(): array {
		return [
			['stepType' => 'installation', 'status' => 'completed', 'mandatory' => true, 'note' => 'Sworn in'],
			['stepType' => 'assign-groups', 'status' => 'open', 'mandatory' => true],
			['stepType' => 'induction-pack', 'status' => 'open', 'mandatory' => false, 'note' => 'keep this'],
		];
	}//end steps()

	/**
	 * The offboarding's steps.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function offboardingSteps(): array {
		return [
			['stepType' => 'end-membership', 'status' => 'open', 'mandatory' => true, 'note' => 'untouched'],
			['stepType' => 'revoke-groups', 'status' => 'open', 'mandatory' => true],
		];
	}//end offboardingSteps()

	/**
	 * Store an onboarding.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	private function givenOnboarding(array $overrides=[]): void {
		$this->records['member-onboarding']['onb-1'] = $overrides + [
			'id'         => 'onb-1',
			'ncAccount'  => 'sem',
			'targetRole' => 'member',
			'targetBody' => 'body-1',
			'steps'      => $this->steps(),
		];
	}//end givenOnboarding()

	/**
	 * Store an offboarding with its membership and person.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	private function givenOffboarding(array $overrides=[]): void {
		$this->records['member-offboarding']['off-1'] = $overrides + [
			'id'         => 'off-1',
			'person'     => 'person-1',
			'body'       => ['id' => 'body-1'],
			'membership' => 'mem-1',
			'endDate'    => '2026-11-30',
			'steps'      => $this->offboardingSteps(),
		];
		$this->records['membership']['mem-1'] = ['id' => 'mem-1'];
		$this->records['person']['person-1']  = ['id' => 'person-1', 'nextcloudUserId' => 'sem'];
	}//end givenOffboarding()

	/**
	 * Store the body with its member groups.
	 *
	 * @param array<string,mixed> $mapping The memberGroups value.
	 *
	 * @return void
	 */
	private function givenBody(array $mapping): void {
		$this->records['governance-body']['body-1'] = ['id' => 'body-1', 'memberGroups' => $mapping];
	}//end givenBody()

	/**
	 * Let the group manager return these groups by id, and null otherwise.
	 *
	 * @param array<string, IGroup> $groups Groups by id.
	 *
	 * @return void
	 */
	private function givenGroups(array $groups): void {
		$this->groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($groups[$gid] ?? null));
	}//end givenGroups()

	/**
	 * A group mock.
	 *
	 * @param string $gid    The group id.
	 * @param bool   $member Whether the member is already in it.
	 *
	 * @return IGroup&MockObject
	 */
	private function group(string $gid, bool $member): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('inGroup')->willReturn($member);
		return $group;
	}//end group()

}//end class
