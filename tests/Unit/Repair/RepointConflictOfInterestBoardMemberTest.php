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
		$entity = $this->createMock(ObjectEntity::class);
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

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				return array_map(fn (array $r) => $this->entity($r), $rows);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				// participant-1 still exists as a live Participant — not yet migrated.
				if ($schema === 'participant' && $id === 'participant-1') {
					return $this->entity(['id' => 'participant-1']);
				}

				return null;
			}
		);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$savedRef) {
				$savedRef[] = ['object' => $object, 'uuid' => $uuid, 'schema' => $schema];
				return $this->entity($object);
			}
		);

		$resolver = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->once())
			->method('resolve')
			->with('participant-1')
			->willReturn(['person' => 'person-1', 'membership' => 'membership-1']);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('info');

		$step->run(output: $output);

		$this->assertCount(1, $saved);
		$this->assertSame('membership-1', $saved[0]['object']['boardMember']);
		$this->assertSame('coi-1', $saved[0]['uuid']);

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

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity($r), $rows)
		);
		// No id ever resolves as a live Participant — boardMember is already a Membership.
		$objectService->method('find')->willReturn(null);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$savedRef) {
				$savedRef[] = $object;
				return $this->entity($object);
			}
		);

		$resolver = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $saved, 'An already-migrated row must not be rewritten');

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

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $r) => $this->entity($r), $rows)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'participant' && $id === 'participant-orphan') {
					return $this->entity(['id' => 'participant-orphan']);
				}

				return null;
			}
		);
		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$savedRef) {
				$savedRef[] = $object;
				return $this->entity($object);
			}
		);

		$resolver = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$resolver->method('resolve')->willReturn(null);

		$step = new RepointConflictOfInterestBoardMember(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $saved);

	}//end testRunSkipsWhenCrosswalkCannotResolve()

	/**
	 * getName() is descriptive.
	 *
	 * @return void
	 */
	public function testGetNameIsDescriptive(): void {
		$step = new RepointConflictOfInterestBoardMember(
			objectService: $this->createMock(ObjectServiceInterface::class),
			resolver: $this->createMock(ParticipantToPersonMembershipResolver::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertStringContainsString('boardMember', $step->getName());
		$this->assertStringContainsString('Membership', $step->getName());

	}//end testGetNameIsDescriptive()
}//end class
