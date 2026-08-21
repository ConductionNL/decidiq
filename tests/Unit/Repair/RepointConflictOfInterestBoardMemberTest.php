<?php

/**
 * Unit tests for RepointConflictOfInterestBoardMember repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Repair;

use OCA\Decidesk\Repair\RepointConflictOfInterestBoardMember;
use OCA\Decidesk\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RepointConflictOfInterestBoardMember.
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
 */
class RepointConflictOfInterestBoardMemberTest extends TestCase {

	/**
	 * Wrap a plain array as an ObjectEntity double (jsonSerialize()/getObject()
	 * are ObjectEntity's own declared methods, not magic __call, so stubbing
	 * only those is safe — see ParticipantToPersonMembershipResolverTest).
	 *
	 * @param array<string, mixed> $data The object payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A conflict-of-interest row whose boardMember still resolves to a live
	 * Participant is repointed to the crosswalk-resolved Membership uuid.
	 *
	 * @return void
	 */
	public function testRunRepointsUnmigratedRow(): void {
		$rows = [
			['id' => 'coi-1', 'boardMember' => 'participant-1', 'declarationType' => 'financial-interest'],
		];
		$saved = [];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				return array_map(fn (array $r) => $this->entity(data: $r), $rows);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				// Participant-1 still exists as a live Participant — not yet migrated.
				if ($schema === 'participant' && $id === 'participant-1') {
					return $this->entity(data: ['id' => 'participant-1']);
				}

				return null;
			}
		);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedRef) {
				$savedRef[] = ['object' => $object, 'uuid' => $uuid, 'schema' => $schema];
				return $this->entity(data: $object);
			}
		);

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->once())
			->method('resolve')
			->with('participant-1')
			->willReturn(['person' => 'person-1', 'membership' => 'membership-1']);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->atLeastOnce())->method('info');

		$step->run(output: $output);

		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'membership-1', actual: $saved[0]['object']['boardMember']);
		$this->assertSame(expected: 'coi-1', actual: $saved[0]['uuid']);

	}//end testRunRepointsUnmigratedRow()

	/**
	 * A row whose boardMember no longer resolves to a live Participant (i.e.
	 * already holds a Membership uuid) is left untouched — idempotency.
	 *
	 * @return void
	 */
	public function testRunSkipsAlreadyMigratedRow(): void {
		$rows = [
			['id' => 'coi-2', 'boardMember' => 'membership-already', 'declarationType' => 'none'],
		];
		$saved = [];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity(data: $r), $rows)
		);
		// No id ever resolves as a live Participant — boardMember is already a Membership.
		$objectService->method('find')->willReturn(null);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedRef) {
				$savedRef[] = $object;
				return $this->entity(data: $object);
			}
		);

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved, message: 'An already-migrated row must not be rewritten');

	}//end testRunSkipsAlreadyMigratedRow()

	/**
	 * A row whose Participant cannot be resolved by the crosswalk is skipped
	 * (logged), not silently dropped or crashed on.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenCrosswalkCannotResolve(): void {
		$rows = [
			['id' => 'coi-3', 'boardMember' => 'participant-orphan', 'declarationType' => 'none'],
		];
		$saved = [];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity(data: $r), $rows)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'participant' && $id === 'participant-orphan') {
					return $this->entity(data: ['id' => 'participant-orphan']);
				}

				return null;
			}
		);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$savedRef) {
				$savedRef[] = $object;
				return $this->entity(data: $object);
			}
		);

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->method('resolve')->willReturn(null);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSkipsWhenCrosswalkCannotResolve()

	/**
	 * When findAll() itself throws (e.g. the register/schema is not seeded
	 * on this install), run() catches it, logs an info message rather than
	 * an error, and returns immediately without touching find()/resolve()/
	 * saveObject().
	 *
	 * @return void
	 */
	public function testRunReturnsEarlyAndLogsWhenFindAllThrows(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException('no such schema'));
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains(string: 'found no conflict-of-interest schema/objects')
		);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $logger,
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with(
			$this->stringContains(string: 'no conflict-of-interest rows on this install')
		);
		$output->expects($this->never())->method('warning');

		$step->run(output: $output);

	}//end testRunReturnsEarlyAndLogsWhenFindAllThrows()

	/**
	 * A row missing both `id`/`uuid` and a row with an empty `boardMember`
	 * are both counted as skipped without ever reaching the crosswalk —
	 * the resolver and find() are never invoked for either.
	 *
	 * @return void
	 */
	public function testRunSkipsRowsMissingIdOrBoardMember(): void {
		$rows = [
			['boardMember' => 'participant-no-id'],
			['id' => 'coi-empty-member', 'boardMember' => ''],
		];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity(data: $r), $rows)
		);
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$messages = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $output);

		$this->assertStringContainsString(needle: '0 resolved, 0 already migrated, 2 skipped.', haystack: end($messages));

	}//end testRunSkipsRowsMissingIdOrBoardMember()

	/**
	 * A malformed entry in the findAll() result (neither an array nor an
	 * object toArray() can normalise, e.g. a bare `null`) is counted as
	 * skipped rather than crashing the whole run.
	 *
	 * @return void
	 */
	public function testRunSkipsNullEntityFromFindAll(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([null]);
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$messages = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $output);

		$this->assertStringContainsString(needle: '0 resolved, 0 already migrated, 1 skipped.', haystack: end($messages));

	}//end testRunSkipsNullEntityFromFindAll()

	/**
	 * findAll() can hand back plain arrays instead of ObjectEntity instances
	 * (OpenRegister does this for some code paths); toArray() must accept
	 * those directly rather than only entity doubles, and the row must still
	 * migrate normally.
	 *
	 * @return void
	 */
	public function testRunHandlesPlainArrayRowFromFindAll(): void {
		$saved = [];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn(
			[['id' => 'coi-plain', 'boardMember' => 'participant-plain']]
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'participant' && $id === 'participant-plain') {
					return $this->entity(data: ['id' => 'participant-plain']);
				}

				return null;
			}
		);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedRef) {
				$savedRef[] = ['object' => $object, 'uuid' => $uuid];
				return $this->entity(data: $object);
			}
		);

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->method('resolve')->willReturn(['person' => 'person-plain', 'membership' => 'membership-plain']);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'membership-plain', actual: $saved[0]['object']['boardMember']);
		$this->assertSame(expected: 'coi-plain', actual: $saved[0]['uuid']);

	}//end testRunHandlesPlainArrayRowFromFindAll()

	/**
	 * When the entity's jsonSerialize() exists but does not return an array,
	 * toArray() must fall back to getObject() rather than giving up — proven
	 * by a double whose jsonSerialize() returns a scalar and whose
	 * getObject() returns the real payload; the row still migrates.
	 *
	 * @return void
	 */
	public function testRunFallsBackToGetObjectWhenJsonSerializeIsNotArray(): void {
		$saved = [];

		$entity = new class {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'not-an-array';
			}//end jsonSerialize()

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['id' => 'coi-fallback', 'boardMember' => 'participant-fallback'];
			}//end getObject()
		};

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([$entity]);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'participant' && $id === 'participant-fallback') {
					return $this->entity(data: ['id' => 'participant-fallback']);
				}

				return null;
			}
		);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedRef) {
				$savedRef[] = ['object' => $object, 'uuid' => $uuid];
				return $this->entity(data: $object);
			}
		);

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->method('resolve')->willReturn(['person' => 'person-fallback', 'membership' => 'membership-fallback']);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'membership-fallback', actual: $saved[0]['object']['boardMember']);
		$this->assertSame(expected: 'coi-fallback', actual: $saved[0]['uuid']);

	}//end testRunFallsBackToGetObjectWhenJsonSerializeIsNotArray()

	/**
	 * When neither jsonSerialize() nor getObject() yields an array, toArray()
	 * returns null and the row is counted as skipped rather than crashing.
	 *
	 * @return void
	 */
	public function testRunSkipsRowWhenNoNormalisationMethodYieldsArray(): void {
		$entity = new class {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'still-not-an-array';
			}//end jsonSerialize()
		};

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([$entity]);
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$messages = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $output);

		$this->assertStringContainsString(needle: '0 resolved, 0 already migrated, 1 skipped.', haystack: end($messages));

	}//end testRunSkipsRowWhenNoNormalisationMethodYieldsArray()

	/**
	 * When saveObject() throws (e.g. a transient OpenRegister failure), the
	 * row is counted as skipped and both the output and the logger receive a
	 * warning — the failure is surfaced, not swallowed, and does not abort
	 * the rest of the run.
	 *
	 * @return void
	 */
	public function testRunCountsSkippedAndWarnsWhenSaveObjectThrows(): void {
		$rows = [
			['id' => 'coi-save-fails', 'boardMember' => 'participant-save-fails'],
		];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity(data: $r), $rows)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'participant' && $id === 'participant-save-fails') {
					return $this->entity(data: ['id' => 'participant-save-fails']);
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willThrowException(new \RuntimeException('write conflict'));

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->method('resolve')->willReturn(['person' => 'person-save-fails', 'membership' => 'membership-save-fails']);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'failed to save one object'),
			$this->callback(
				callback: static fn (array $context): bool => ($context['id'] ?? null) === 'coi-save-fails'
			)
		);

		$messages = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$output->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'Failed to repoint conflict-of-interest coi-save-fails')
		);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $logger,
		);

		$step->run(output: $output);

		$this->assertStringContainsString(needle: '0 resolved, 0 already migrated, 1 skipped.', haystack: end($messages));

	}//end testRunCountsSkippedAndWarnsWhenSaveObjectThrows()

	/**
	 * When find() itself throws while checking whether boardMember still
	 * resolves to a live Participant, isParticipant() treats that identically
	 * to "not found" — the row is counted as already migrated and the
	 * crosswalk resolver is never consulted. This means a transient
	 * OpenRegister failure while checking a still-unmigrated row is
	 * indistinguishable from a genuinely already-migrated row; see report.
	 *
	 * @return void
	 */
	public function testRunTreatsFindExceptionDuringParticipantCheckAsNotAParticipant(): void {
		$rows = [
			['id' => 'coi-find-throws', 'boardMember' => 'participant-find-throws'],
		];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity(data: $r), $rows)
		);
		$objectService->method('find')->willThrowException(new \RuntimeException('service unavailable'));
		$objectService->expects($this->never())->method('saveObject');

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$messages = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$step->run(output: $output);

		// A failed lookup is UNKNOWN, not "already migrated". Counting it as
		// already-migrated would retire the row from a step whose whole value
		// is that re-running it finishes the job, so a transient OpenRegister
		// outage would leave rows permanently unrepointed. It must land in
		// `skipped`, which keeps it eligible for the next run.
		$this->assertStringContainsString(needle: '0 resolved, 0 already migrated, 1 skipped.', haystack: end($messages));

	}//end testRunTreatsFindExceptionDuringParticipantCheckAsNotAParticipant()

	/**
	 * getName() is descriptive.
	 *
	 * @return void
	 */
	public function testGetNameIsDescriptive(): void {
		$step = new RepointConflictOfInterestBoardMember(
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			resolver: $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertStringContainsString(needle: 'boardMember', haystack: $step->getName());
		$this->assertStringContainsString(needle: 'Membership', haystack: $step->getName());

	}//end testGetNameIsDescriptive()
}//end class
