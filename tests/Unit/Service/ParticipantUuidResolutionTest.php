<?php

/**
 * Characterisation test for the Nextcloud-user to Participant resolution.
 *
 * ConflictOfInterestAuthorizationGuard and ProxyVoteService each resolve the
 * caller's Participant UUID through a private `resolveParticipantUuid()`. This
 * pins the lookup itself for both, and the allowed and refused outcomes of the
 * guard decisions that depend on it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ConflictOfInterestAuthorizationGuard;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\Decidiq\Service\ProxyVoteService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins resolveParticipantUuid() and the guard outcomes built on it.
 */
class ParticipantUuidResolutionTest extends TestCase {

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The participant resolver mock.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver $participantResolver;

	/**
	 * The crosswalk mock.
	 *
	 * @var ParticipantToPersonMembershipResolver&MockObject
	 */
	private ParticipantToPersonMembershipResolver $crosswalk;

	/**
	 * The findAll() configs received, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The entities findAll() answers.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $entities = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService       = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->participantResolver = $this->createMock(originalClassName: ParticipantResolver::class);
		$this->crosswalk           = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->queries[] = $config;
				return $this->entities;
			}
		);

	}//end setUp()

	/**
	 * The classes carrying resolveParticipantUuid().
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function owners(): array {
		return [
			'conflict of interest guard' => [ConflictOfInterestAuthorizationGuard::class],
			'proxy vote service' => [ProxyVoteService::class],
		];

	}//end owners()

	/**
	 * The first participant's uuid answers, then its id, else null.
	 *
	 * @param class-string $class The owner under test.
	 *
	 * @return void
	 */
	#[DataProvider('owners')]
	public function testLookup(string $class): void {
		$this->participants(rows: [['uuid' => 'p-uuid', 'id' => 'p-id'], ['uuid' => 'second']]);
		$this->objectService->expects($this->exactly(count: 4))->method('setRegister')->with('decidiq');
		$this->objectService->expects($this->exactly(count: 4))->method('setSchema')->with('participant');
		self::assertSame(expected: 'p-uuid', actual: $this->lookup(class: $class, uid: 'alice'));

		$this->participants(rows: [['id' => 'p-id']]);
		self::assertSame(expected: 'p-id', actual: $this->lookup(class: $class, uid: 'bob'));

		$this->participants(rows: [['name' => 'no identifiers']]);
		self::assertNull(actual: $this->lookup(class: $class, uid: 'carol'));

		$this->participants(rows: []);
		self::assertNull(actual: $this->lookup(class: $class, uid: 'dave'));

		self::assertSame(
			expected: [
				['filters' => ['nextcloudUserId' => 'alice']],
				['filters' => ['nextcloudUserId' => 'bob']],
				['filters' => ['nextcloudUserId' => 'carol']],
				['filters' => ['nextcloudUserId' => 'dave']],
			],
			actual: $this->queries
		);

	}//end testLookup()

	/**
	 * The member themself is allowed without any meeting lookup.
	 *
	 * @return void
	 */
	public function testMemberThemselfIsAllowed(): void {
		$this->participants(rows: [['uuid' => 'p-1']]);
		$this->crosswalk->method('resolve')->with('p-1')->willReturn(['membership' => 'm-1']);
		$this->objectService->expects($this->never())->method('find');

		self::assertTrue(condition: $this->guard()->isAuthorizedForMember(membershipId: 'm-1', agendaItemId: 'a-1', callerUid: 'alice'));

	}//end testMemberThemselfIsAllowed()

	/**
	 * Another member, or an unknown user, without chair authority is refused.
	 *
	 * @return void
	 */
	public function testOthersWithoutMeetingAreRefused(): void {
		$this->participants(rows: [['uuid' => 'p-1']]);
		$this->crosswalk->method('resolve')->willReturn(['membership' => 'm-other']);
		$this->objectService->method('find')->willReturn(null);

		self::assertFalse(condition: $this->guard()->isAuthorizedForMember(membershipId: 'm-1', agendaItemId: 'a-1', callerUid: 'alice'));

		$this->participants(rows: []);
		self::assertFalse(condition: $this->guard()->isAuthorizedForMember(membershipId: 'm-1', agendaItemId: 'a-1', callerUid: 'nobody'));

	}//end testOthersWithoutMeetingAreRefused()

	/**
	 * The chair of the agenda item's meeting is allowed for another member.
	 *
	 * @return void
	 */
	public function testChairIsAllowedForAnotherMember(): void {
		$this->participants(rows: []);
		$item = new ObjectEntity();
		$item->setUuid('a-1');
		$item->setObject(['meeting' => 'meeting-1']);
		$this->objectService->method('find')->willReturn($item);
		$this->participantResolver->method('hasRole')->willReturnCallback(
			static fn (string $meetingId, string $nextcloudUid, array $roles): bool => $meetingId === 'meeting-1' && $nextcloudUid === 'chair'
		);

		self::assertTrue(condition: $this->guard()->isAuthorizedForMember(membershipId: 'm-1', agendaItemId: 'a-1', callerUid: 'chair'));
		self::assertFalse(condition: $this->guard()->isAuthorizedForMember(membershipId: 'm-1', agendaItemId: 'a-1', callerUid: 'member'));

	}//end testChairIsAllowedForAnotherMember()

	/**
	 * membershipForUser() follows the participant to its membership.
	 *
	 * @return void
	 */
	public function testMembershipForUser(): void {
		$this->participants(rows: [['uuid' => 'p-1']]);
		$this->crosswalk->method('resolve')->willReturnCallback(
			static fn (string $participantId): array => ['membership' => 'm-of-' . $participantId]
		);
		self::assertSame(expected: 'm-of-p-1', actual: $this->guard()->membershipForUser(uid: 'alice'));

		$this->participants(rows: []);
		self::assertNull(actual: $this->guard()->membershipForUser(uid: 'nobody'));

	}//end testMembershipForUser()

	/**
	 * Make findAll() answer the given participant rows.
	 *
	 * @param list<array<string, mixed>> $rows The participants.
	 *
	 * @return void
	 */
	private function participants(array $rows): void {
		$this->entities = [];
		foreach ($rows as $row) {
			$entity = $this->createMock(originalClassName: ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn($row);
			$this->entities[] = $entity;
		}

	}//end participants()

	/**
	 * Call the private resolveParticipantUuid() of an owner.
	 *
	 * @param class-string $class The owner.
	 * @param string       $uid   The Nextcloud user id.
	 *
	 * @return string|null The participant uuid.
	 */
	private function lookup(string $class, string $uid): ?string {
		$owner = $this->build(class: $class);

		return (new ReflectionClass($class))->getMethod('resolveParticipantUuid')->invoke($owner, $uid);

	}//end lookup()

	/**
	 * The guard over the mocks.
	 *
	 * @return ConflictOfInterestAuthorizationGuard The guard.
	 */
	private function guard(): ConflictOfInterestAuthorizationGuard {
		return $this->build(class: ConflictOfInterestAuthorizationGuard::class);

	}//end guard()

	/**
	 * Build an owner, every constructor dependency a mock except the shared ones.
	 *
	 * @param class-string $class The owner.
	 *
	 * @return object The owner.
	 */
	private function build(string $class): object {
		$shared = [
			ObjectServiceInterface::class => $this->objectService,
			ParticipantResolver::class => $this->participantResolver,
			ParticipantToPersonMembershipResolver::class => $this->crosswalk,
		];
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			assert($type instanceof ReflectionNamedType);
			$arguments[$parameter->getName()] = ($shared[$type->getName()] ?? $this->createMock(originalClassName: $type->getName()));
		}

		return new $class(...$arguments);

	}//end build()
}//end class
