<?php

/**
 * Unit tests for ApprovalActionStateRepair.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalActionStateRepair;
use OCA\Decidiq\Service\OpenApprovalGuard;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The backfill of `state` onto approval actions stored before the engine wrote it.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */
#[CoversClass(ApprovalActionStateRepair::class)]
#[UsesClass(OpenApprovalGuard::class)]
final class ApprovalActionStateRepairTest extends TestCase {

	/**
	 * A store holding the given rows, which records every patch and applies it.
	 *
	 * @param array<string, array<string, mixed>> $rows    The stored actions, by uuid.
	 * @param array<int, array<string, mixed>>    $patches Receives every patch.
	 *
	 * @return RegisterObjectStore The store.
	 */
	private function store(array &$rows, array &$patches): RegisterObjectStore {
		$store = $this->createMock(RegisterObjectStore::class);
		$store->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters) use (&$rows): array {
				self::assertSame('approval-action', $schema);
				self::assertSame([], $filters);

				return array_values($rows);
			}
		);
		$store->method('patch')->willReturnCallback(
			static function (string $schema, array $data, string $uuid) use (&$rows, &$patches): array {
				$patches[] = ['schema' => $schema, 'data' => $data, 'uuid' => $uuid];
				$rows[$uuid] = ($data + $rows[$uuid]);

				return $rows[$uuid];
			}
		);

		return $store;

	}//end store()

	/**
	 * A recorded act reading `open` (or no state at all) gets the state its verb
	 * ends in; a real work item and a terminal state are left alone.
	 *
	 * @return void
	 */
	public function testOnlyRecordedActsWithoutATerminalStateAreRepaired(): void {
		$rows = [
			'a-1' => ['id' => 'a-1', 'action' => 'approved', 'state' => 'open'],
			'a-2' => ['id' => 'a-2', 'action' => 'returned'],
			'a-3' => ['id' => 'a-3', 'action' => '', 'state' => 'open', 'assignee' => 'j.jansen'],
			'a-4' => ['id' => 'a-4', 'action' => 'endorsed', 'state' => 'withdrawn'],
			'a-5' => ['id' => 'a-5', 'action' => 'lapsed', 'state' => 'refused'],
			'a-6' => ['id' => 'a-6', 'action' => 'pondered', 'state' => 'open'],
		];
		$patches = [];

		$repaired = (new ApprovalActionStateRepair(store: $this->store($rows, $patches)))->repair();

		self::assertSame(2, $repaired);
		self::assertSame(
			[
				['schema' => 'approval-action', 'data' => ['state' => 'granted'], 'uuid' => 'a-1'],
				['schema' => 'approval-action', 'data' => ['state' => 'refused'], 'uuid' => 'a-2'],
			],
			$patches
		);

	}//end testOnlyRecordedActsWithoutATerminalStateAreRepaired()

	/**
	 * A second run finds nothing left to repair.
	 *
	 * @return void
	 */
	public function testARerunRepairsNothing(): void {
		$rows = ['a-1' => ['id' => 'a-1', 'action' => 'skipped', 'state' => 'open']];
		$patches = [];
		$repair = new ApprovalActionStateRepair(store: $this->store($rows, $patches));

		self::assertSame(1, $repair->repair());
		self::assertSame('granted', $rows['a-1']['state']);
		self::assertSame(0, $repair->repair());
		self::assertCount(1, $patches);

	}//end testARerunRepairsNothing()
}//end class
