<?php

/**
 * Unit tests for ParticipantToPersonMembershipResolver.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-1-crosswalk-resolver--match-by-email-else-create
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ParticipantToPersonMembershipResolver.
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-1-crosswalk-resolver--match-by-email-else-create
 */
class ParticipantToPersonMembershipResolverTest extends TestCase {

	/**
	 * Wrap a plain array as an ObjectEntity double whose jsonSerialize()/
	 * getObject() return it — the same pattern already used across this
	 * app's other ObjectService-backed test doubles (ObjectEntity's own
	 * accessors are magic __call, so only its REAL declared methods are
	 * stubbed here, never mocked-as-magic).
	 *
	 * @param array<string, mixed> $data The object payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Build a resolver backed by in-memory participant/person/membership
	 * tables, keyed by schema slug.
	 *
	 * @param array<string, mixed>|null $participant Fixture participant row, keyed by its id
	 * @param array<int, array<string, mixed>> $persons Fixture person rows
	 * @param array<int, array<string, mixed>> $memberships Fixture membership rows
	 * @param array<int, array<string, mixed>> &$savedPersons Captured Person saves
	 * @param array<int, array<string, mixed>> &$savedMemberships Captured Membership saves
	 * @param bool $throwOnParticipantFind Make find() throw for the Participant lookup
	 * @param bool $throwOnPersonFindAll Make findAll() throw for the Person lookup
	 * @param bool $throwOnMembershipFindAll Make findAll() throw for the Membership lookup
	 * @param bool $throwOnPersonCreateSave Make saveObject() throw when creating a new Person (no uuid)
	 * @param bool $throwOnPersonBackfillSave Make saveObject() throw when backfilling an existing Person (uuid set)
	 * @param bool $throwOnMembershipSave Make saveObject() throw when creating a Membership
	 *
	 * @return ParticipantToPersonMembershipResolver
	 */
	private function makeResolver(
		?array $participant,
		array $persons,
		array $memberships,
		array &$savedPersons = [],
		array &$savedMemberships = [],
		bool $throwOnParticipantFind = false,
		bool $throwOnPersonFindAll = false,
		bool $throwOnMembershipFindAll = false,
		bool $throwOnPersonCreateSave = false,
		bool $throwOnPersonBackfillSave = false,
		bool $throwOnMembershipSave = false,
	): ParticipantToPersonMembershipResolver {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($participant, $throwOnParticipantFind) {
				if ($throwOnParticipantFind === true) {
					throw new RuntimeException('Participant lookup failed.');
				}

				if ($schema === 'participant' && $participant !== null && ($participant['id'] ?? null) === $id) {
					return $this->entity($participant);
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($persons, $memberships, $throwOnPersonFindAll, $throwOnMembershipFindAll): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? '');

				if ($schema === 'person') {
					if ($throwOnPersonFindAll === true) {
						throw new RuntimeException('Person lookup failed.');
					}

					foreach (['nextcloudUserId', 'email'] as $field) {
						if (array_key_exists($field, $filters) === true) {
							foreach ($persons as $person) {
								if (($person[$field] ?? null) === $filters[$field]) {
									return [$this->entity($person)];
								}
							}

							return [];
						}
					}
				}

				if ($schema === 'membership') {
					if ($throwOnMembershipFindAll === true) {
						throw new RuntimeException('Membership lookup failed.');
					}

					foreach ($memberships as $membership) {
						if (($membership['person'] ?? null) === ($filters['person'] ?? null)
							&& ($membership['governanceBody'] ?? null) === ($filters['governanceBody'] ?? null)
						) {
							return [$this->entity($membership)];
						}
					}

					return [];
				}

				return [];
			}
		);

		$savedPersonsRef = &$savedPersons;
		$savedMembershipsRef = &$savedMemberships;
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedPersonsRef, &$savedMembershipsRef, $throwOnPersonCreateSave, $throwOnPersonBackfillSave, $throwOnMembershipSave) {
				if ($schema === 'person') {
					if ($uuid === null && $throwOnPersonCreateSave === true) {
						throw new RuntimeException('Person create save failed.');
					}

					if ($uuid !== null && $throwOnPersonBackfillSave === true) {
						throw new RuntimeException('Person backfill save failed.');
					}

					$row = array_merge(['id' => $uuid ?? ('person-' . count($savedPersonsRef))], $object);
					$savedPersonsRef[] = $row;
					return $this->entity($row);
				}

				if ($throwOnMembershipSave === true) {
					throw new RuntimeException('Membership save failed.');
				}

				$row = array_merge(['id' => $uuid ?? ('membership-' . count($savedMembershipsRef))], $object);
				$savedMembershipsRef[] = $row;
				return $this->entity($row);
			}
		);

		return new ParticipantToPersonMembershipResolver(
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeResolver()

	/**
	 * resolve() returns null when the Participant itself cannot be loaded.
	 *
	 * @return void
	 */
	public function testResolveReturnsNullForUnknownParticipant(): void {
		$resolver = $this->makeResolver(participant: null, persons: [], memberships: []);

		$this->assertNull($resolver->resolve('missing'));

	}//end testResolveReturnsNullForUnknownParticipant()

	/**
	 * Match order: an existing Person is matched by nextcloudUserId before
	 * email is even consulted (judge amendment).
	 *
	 * @return void
	 */
	public function testResolveMatchesByNextcloudUserIdFirst(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: [
				'id' => 'p1',
				'displayName' => 'Anna',
				'email' => 'wrong@example.com',
				'nextcloudUserId' => 'anna',
				'role' => 'member',
				'governanceBody' => 'gb-1',
			],
			persons: [['id' => 'person-1', 'name' => 'Anna', 'nextcloudUserId' => 'anna', 'email' => 'anna@example.com']],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
		);

		$result = $resolver->resolve('p1');

		$this->assertSame('person-1', $result['person']);
		$this->assertSame([], $savedPersons, 'An exact nextcloudUserId match needs no create/backfill');

	}//end testResolveMatchesByNextcloudUserIdFirst()

	/**
	 * When no nextcloudUserId match exists, email is tried next, and the
	 * matched Person is backfilled with nextcloudUserId (judge amendment).
	 *
	 * @return void
	 */
	public function testResolveMatchesByEmailAndBackfillsNextcloudUserId(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: [
				'id' => 'p1',
				'displayName' => 'Bram',
				'email' => 'bram@example.com',
				'nextcloudUserId' => 'bram',
				'role' => 'member',
				'governanceBody' => 'gb-1',
			],
			persons: [['id' => 'person-2', 'name' => 'Bram', 'email' => 'bram@example.com']],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
		);

		$result = $resolver->resolve('p1');

		$this->assertSame('person-2', $result['person']);
		$this->assertCount(1, $savedPersons, 'The matched Person is backfilled with nextcloudUserId');
		$this->assertSame('bram', $savedPersons[0]['nextcloudUserId']);

	}//end testResolveMatchesByEmailAndBackfillsNextcloudUserId()

	/**
	 * With no match on either key, a new Person + Membership pair is created
	 * from the Participant's fields.
	 *
	 * @return void
	 */
	public function testResolveCreatesPersonAndMembershipWhenUnmatched(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: [
				'id' => 'p1',
				'displayName' => 'Carla',
				'email' => 'carla@example.com',
				'role' => 'chair',
				'party' => 'GroenLinks',
				'votingWeight' => 2,
				'governanceBody' => 'gb-1',
			],
			persons: [],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
		);

		$result = $resolver->resolve('p1');

		$this->assertCount(1, $savedPersons);
		$this->assertSame('Carla', $savedPersons[0]['name']);
		$this->assertSame('carla@example.com', $savedPersons[0]['email']);

		$this->assertCount(1, $savedMemberships);
		$this->assertSame('chair', $savedMemberships[0]['role']);
		$this->assertSame('GroenLinks', $savedMemberships[0]['party']);
		$this->assertSame('gb-1', $savedMemberships[0]['governanceBody']);
		$this->assertSame($savedPersons[0]['id'], $result['person']);
		$this->assertSame($savedMemberships[0]['id'], $result['membership']);

	}//end testResolveCreatesPersonAndMembershipWhenUnmatched()

	/**
	 * An existing Membership for the same Person + GovernanceBody is reused,
	 * not duplicated (idempotency, Decision 1 step 5).
	 *
	 * @return void
	 */
	public function testResolveReusesExistingMembershipForSameGovernanceBody(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: ['id' => 'p1', 'displayName' => 'Dana', 'nextcloudUserId' => 'dana', 'role' => 'member', 'governanceBody' => 'gb-1'],
			persons: [['id' => 'person-3', 'name' => 'Dana', 'nextcloudUserId' => 'dana']],
			memberships: [['id' => 'membership-3', 'person' => 'person-3', 'governanceBody' => 'gb-1', 'role' => 'member']],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
		);

		$result = $resolver->resolve('p1');

		$this->assertSame('membership-3', $result['membership']);
		$this->assertSame([], $savedMemberships, 'An existing Membership for this Person+GovernanceBody is reused, not duplicated');

	}//end testResolveReusesExistingMembershipForSameGovernanceBody()

	/**
	 * When the Person lookup itself throws (OpenRegister unavailable for the
	 * `person` schema), resolvePerson() must not crash — it falls through to
	 * creating a brand new Person, and resolve() still succeeds.
	 *
	 * @return void
	 */
	public function testResolveFallsThroughToCreatePersonWhenPersonLookupThrows(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: [
				'id' => 'p1',
				'displayName' => 'Eva',
				'email' => 'eva@example.com',
				'nextcloudUserId' => 'eva',
				'role' => 'member',
				'governanceBody' => 'gb-1',
			],
			persons: [],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
			throwOnPersonFindAll: true,
		);

		$result = $resolver->resolve('p1');

		$this->assertNotNull($result, 'A failed Person lookup must fall through to creating a new Person, not crash');
		$this->assertCount(1, $savedPersons);
		$this->assertSame('Eva', $savedPersons[0]['name']);
		$this->assertSame($savedPersons[0]['id'], $result['person']);

	}//end testResolveFallsThroughToCreatePersonWhenPersonLookupThrows()

	/**
	 * When creating a brand new Person fails to save, the returned payload
	 * carries no id/uuid, so personId stays empty and resolve() must return
	 * null rather than a partial pair — and must never attempt to create a
	 * Membership for a Person that does not exist.
	 *
	 * @return void
	 */
	public function testResolveReturnsNullWhenPersonCreateSaveThrows(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: ['id' => 'p1', 'displayName' => 'Fenna', 'email' => 'fenna@example.com', 'role' => 'member', 'governanceBody' => 'gb-1'],
			persons: [],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
			throwOnPersonCreateSave: true,
		);

		$result = $resolver->resolve('p1');

		$this->assertNull($result, 'A Person that failed to save carries no id, so resolve() must return null');
		$this->assertSame([], $savedPersons);
		$this->assertSame([], $savedMemberships, 'resolve() must bail out before attempting to create a Membership');

	}//end testResolveReturnsNullWhenPersonCreateSaveThrows()

	/**
	 * When backfilling `nextcloudUserId` onto an already-matched Person fails
	 * to save, backfillNextcloudUserId() must not crash or drop the match —
	 * resolve() still succeeds, returning the ORIGINAL matched Person's id,
	 * and still proceeds to resolve the Membership.
	 *
	 * @return void
	 */
	public function testResolveKeepsMatchedPersonWhenBackfillSaveThrows(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: [
				'id' => 'p1',
				'displayName' => 'Gerrit',
				'email' => 'gerrit@example.com',
				'nextcloudUserId' => 'gerrit',
				'role' => 'member',
				'governanceBody' => 'gb-1',
			],
			persons: [['id' => 'person-9', 'name' => 'Gerrit', 'email' => 'gerrit@example.com']],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
			throwOnPersonBackfillSave: true,
		);

		$result = $resolver->resolve('p1');

		$this->assertNotNull($result, 'A failed backfill save must not crash or drop the already-matched Person');
		$this->assertSame('person-9', $result['person']);
		$this->assertSame([], $savedPersons, 'The failed backfill save must not be recorded as a successful save');
		$this->assertCount(1, $savedMemberships, 'resolve() must still proceed to create the Membership');

	}//end testResolveKeepsMatchedPersonWhenBackfillSaveThrows()

	/**
	 * A Person matched by email but carrying neither `id` nor `uuid` can
	 * never produce a personId, so backfillNextcloudUserId() must skip the
	 * save entirely (not attempt and fail it), and that same empty personId
	 * must propagate back up to resolve(), which returns null.
	 *
	 * @return void
	 */
	public function testResolveReturnsNullWhenEmailMatchedPersonHasNoIdForBackfill(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: [
				'id' => 'p1',
				'displayName' => 'Hana',
				'email' => 'hana@example.com',
				'nextcloudUserId' => 'hana',
				'role' => 'member',
				'governanceBody' => 'gb-1',
			],
			persons: [['name' => 'Hana', 'email' => 'hana@example.com']],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
		);

		$result = $resolver->resolve('p1');

		$this->assertNull($result, 'A matched Person with neither id nor uuid can never carry a personId, so resolve() must return null');
		$this->assertSame([], $savedPersons, 'A personId-less Person must skip the backfill save entirely, not attempt and fail it');
		$this->assertSame([], $savedMemberships, 'resolve() must bail out before attempting to create a Membership');

	}//end testResolveReturnsNullWhenEmailMatchedPersonHasNoIdForBackfill()

	/**
	 * When a Participant carries no `governanceBody`, resolveMembership()
	 * must skip the existing-Membership lookup entirely rather than search
	 * with an empty governanceBodyId filter, and the created Membership
	 * payload must not carry a 'governanceBody' key at all.
	 *
	 * @return void
	 */
	public function testResolveSkipsMembershipLookupWhenGovernanceBodyIsAbsent(): void {
		$participant = ['id' => 'p1', 'displayName' => 'Ivo', 'nextcloudUserId' => 'ivo', 'role' => 'member'];
		$persons = [['id' => 'person-4', 'name' => 'Ivo', 'nextcloudUserId' => 'ivo']];

		$membershipFindAllCalled = false;
		$savedMemberships = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($participant) {
				if ($schema === 'participant' && ($participant['id'] ?? null) === $id) {
					return $this->entity($participant);
				}

				return null;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($persons, &$membershipFindAllCalled): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? '');

				if ($schema === 'membership') {
					$membershipFindAllCalled = true;
					return [];
				}

				if ($schema === 'person') {
					foreach (['nextcloudUserId', 'email'] as $field) {
						if (array_key_exists($field, $filters) === true) {
							foreach ($persons as $person) {
								if (($person[$field] ?? null) === $filters[$field]) {
									return [$this->entity($person)];
								}
							}

							return [];
						}
					}
				}

				return [];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedMemberships) {
				$row = array_merge(['id' => $uuid ?? ('membership-' . count($savedMemberships))], $object);
				$savedMemberships[] = $row;
				return $this->entity($row);
			}
		);

		$resolver = new ParticipantToPersonMembershipResolver(
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $resolver->resolve('p1');

		$this->assertNotNull($result);
		$this->assertFalse($membershipFindAllCalled, 'An absent governanceBody must skip the existing-Membership lookup entirely');
		$this->assertCount(1, $savedMemberships);
		$this->assertArrayNotHasKey('governanceBody', $savedMemberships[0], 'A Membership created without a governanceBody must not carry the key at all');

	}//end testResolveSkipsMembershipLookupWhenGovernanceBodyIsAbsent()

	/**
	 * When creating a new Membership fails to save, resolveMembership()
	 * returns an empty string, and resolve() must return null rather than a
	 * partial pair.
	 *
	 * @return void
	 */
	public function testResolveReturnsNullWhenMembershipSaveThrows(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: ['id' => 'p1', 'displayName' => 'Joke', 'nextcloudUserId' => 'joke', 'role' => 'member', 'governanceBody' => 'gb-1'],
			persons: [['id' => 'person-5', 'name' => 'Joke', 'nextcloudUserId' => 'joke']],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
			throwOnMembershipSave: true,
		);

		$result = $resolver->resolve('p1');

		$this->assertNull($result, 'A Membership that failed to save must make resolve() return null rather than a partial pair');
		$this->assertSame([], $savedMemberships);

	}//end testResolveReturnsNullWhenMembershipSaveThrows()

	/**
	 * When the existing-Membership lookup itself throws, findOneMembership()
	 * must not crash — resolveMembership() falls through to creating a new
	 * Membership despite governanceBodyId being present and despite a
	 * matching Membership actually existing in the fixture (proving the
	 * lookup failure, not an absent row, drove the fall-through).
	 *
	 * @return void
	 */
	public function testResolveFallsThroughToCreateMembershipWhenMembershipLookupThrows(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: ['id' => 'p1', 'displayName' => 'Karel', 'nextcloudUserId' => 'karel', 'role' => 'member', 'governanceBody' => 'gb-1'],
			persons: [['id' => 'person-6', 'name' => 'Karel', 'nextcloudUserId' => 'karel']],
			memberships: [['id' => 'membership-6', 'person' => 'person-6', 'governanceBody' => 'gb-1', 'role' => 'member']],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
			throwOnMembershipFindAll: true,
		);

		$result = $resolver->resolve('p1');

		$this->assertNotNull($result, 'A failed Membership lookup must fall through to creating a new Membership, not crash');
		$this->assertCount(1, $savedMemberships, 'The lookup failure must not be mistaken for an existing Membership already found');
		$this->assertSame($savedMemberships[0]['id'], $result['membership']);

	}//end testResolveFallsThroughToCreateMembershipWhenMembershipLookupThrows()

	/**
	 * When loading the source Participant itself throws (OpenRegister
	 * unavailable), loadParticipant() must not let the exception propagate —
	 * resolve() must return null, and must never attempt to resolve a Person
	 * or Membership for a Participant it could not load.
	 *
	 * @return void
	 */
	public function testResolveReturnsNullWhenParticipantLookupThrows(): void {
		$savedPersons = [];
		$savedMemberships = [];
		$resolver = $this->makeResolver(
			participant: ['id' => 'p1', 'displayName' => 'Lotte', 'role' => 'member'],
			persons: [],
			memberships: [],
			savedPersons: $savedPersons,
			savedMemberships: $savedMemberships,
			throwOnParticipantFind: true,
		);

		$result = $resolver->resolve('p1');

		$this->assertNull($result, 'A Participant lookup failure must not propagate as an exception; resolve() must return null');
		$this->assertSame([], $savedPersons);
		$this->assertSame([], $savedMemberships);

	}//end testResolveReturnsNullWhenParticipantLookupThrows()
}//end class
