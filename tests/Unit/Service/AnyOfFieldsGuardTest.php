<?php

/**
 * AnyOfFieldsGuard: a group of fields of which at least one must be filled.
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
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AnyOfFieldsGuard;
use OCA\Decidiq\Service\OndermandaatGuardService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\AnyOfFieldsGuard
 */
class AnyOfFieldsGuardTest extends TestCase {

	/**
	 * Two groups of two fields.
	 *
	 * @var array<string, list<string>>
	 */
	private const GROUPS = ['from' => ['body', 'description'], 'to' => ['role', 'person']];

	/**
	 * Every group with a filled field leaves nothing empty.
	 *
	 * @return void
	 */
	public function testEveryGroupFilledLeavesNothingEmpty(): void {
		self::assertSame([], (new AnyOfFieldsGuard())->emptyGroups(row: ['description' => 'burgemeester', 'person' => ['id' => 'p1']], groups: self::GROUPS));
	}//end testEveryGroupFilledLeavesNothingEmpty()

	/**
	 * Blank strings, null, an empty list and absent fields are all empty, and the groups come back in order.
	 *
	 * @return void
	 */
	public function testBlankNullAndEmptyListsCountAsEmpty(): void {
		$row = ['body' => '   ', 'description' => null, 'role' => [], 'unrelated' => 'x'];

		self::assertSame(['from', 'to'], (new AnyOfFieldsGuard())->emptyGroups(row: $row, groups: self::GROUPS));
	}//end testBlankNullAndEmptyListsCountAsEmpty()

	/**
	 * Numbers and booleans are values, so only the group without one is named.
	 *
	 * @return void
	 */
	public function testNumbersAndBooleansCountAsFilled(): void {
		$guard = new AnyOfFieldsGuard();

		self::assertSame(['to'], $guard->emptyGroups(row: ['body' => 0], groups: self::GROUPS));
		self::assertSame(['from'], $guard->emptyGroups(row: ['person' => false, 'description' => ''], groups: self::GROUPS));
		self::assertSame([], $guard->emptyGroups(row: ['body' => 1.5, 'role' => 'x'], groups: self::GROUPS));
	}//end testNumbersAndBooleansCountAsFilled()

	/**
	 * The toedeling party groups name the current and the old Dutch delegans and delegataris fields.
	 *
	 * @return void
	 */
	public function testTheToedelingPartyGroupsCoverTheCurrentAndTheOldFieldNames(): void {
		$guard = new AnyOfFieldsGuard();

		self::assertSame([], $guard->emptyGroups(row: ['delegatingBody' => 'b', 'delegateBody' => 'c'], groups: OndermandaatGuardService::PARTY_GROUPS));
		self::assertSame([], $guard->emptyGroups(row: ['delegansDescription' => 'b', 'delegateUser' => 'jdoe'], groups: OndermandaatGuardService::PARTY_GROUPS));
		self::assertCount(2, $guard->emptyGroups(row: ['subject' => 'x', 'decision' => 'd'], groups: OndermandaatGuardService::PARTY_GROUPS));
	}//end testTheToedelingPartyGroupsCoverTheCurrentAndTheOldFieldNames()
}//end class
