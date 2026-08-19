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
	 *
	 * @return ParticipantToPersonMembershipResolver
	 */
	private function makeResolver(
		?array $participant,
		array $persons,
		array $memberships,
		array &$savedPersons = [],
		array &$savedMemberships = [],
	): ParticipantToPersonMembershipResolver {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($participant) {
				if ($schema === 'participant' && $participant !== null && ($participant['id'] ?? null) === $id) {
					return $this->entity($participant);
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($persons, $memberships): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? '');

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

				if ($schema === 'membership') {
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
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$savedPersonsRef, &$savedMembershipsRef) {
				if ($schema === 'person') {
					$row = array_merge(['id' => $uuid ?? ('person-' . count($savedPersonsRef))], $object);
					$savedPersonsRef[] = $row;
					return $this->entity($row);
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
			participant: ['id' => 'p1', 'displayName' => 'Anna', 'email' => 'wrong@example.com', 'nextcloudUserId' => 'anna', 'role' => 'member', 'governanceBody' => 'gb-1'],
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
			participant: ['id' => 'p1', 'displayName' => 'Bram', 'email' => 'bram@example.com', 'nextcloudUserId' => 'bram', 'role' => 'member', 'governanceBody' => 'gb-1'],
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
			participant: ['id' => 'p1', 'displayName' => 'Carla', 'email' => 'carla@example.com', 'role' => 'chair', 'party' => 'GroenLinks', 'votingWeight' => 2, 'governanceBody' => 'gb-1'],
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
}//end class
