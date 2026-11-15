<?php

/**
 * Characterisation test for refId() of the agenda, recusal and meeting-record code.
 *
 * AgendaItemCopier, RecusalGuard and MeetingScopedRecords each read the id out
 * of a stored reference through `refId()`: a string is the id, an array yields
 * its `id` then `uuid`, anything else is empty. This pins those outcomes.
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

use OCA\Decidiq\Service\AgendaItemCopier;
use OCA\Decidiq\Service\MeetingScopedRecords;
use OCA\Decidiq\Service\RecusalGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins `refId()` of the three classes that carry it.
 */
class ReferenceIdTest extends TestCase {

	/**
	 * The classes whose refId() is pinned.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function owners(): array {
		return [
			'agenda item copier' => [AgendaItemCopier::class],
			'recusal guard' => [RecusalGuard::class],
			'meeting scoped records' => [MeetingScopedRecords::class],
		];

	}//end owners()

	/**
	 * Strings are ids, arrays yield id then uuid, the rest is empty.
	 *
	 * @param class-string $class The class under test.
	 *
	 * @return void
	 */
	#[DataProvider('owners')]
	public function testReferences(string $class): void {
		$cases = [
			['meeting-1', 'meeting-1'],
			['', ''],
			[['id' => 'by-id', 'uuid' => 'by-uuid'], 'by-id'],
			[['uuid' => 'by-uuid'], 'by-uuid'],
			[['id' => 42], '42'],
			[['id' => null, 'uuid' => null], ''],
			[[], ''],
			[null, ''],
			[7, ''],
			[true, ''],
		];
		foreach ($cases as [$ref, $expected]) {
			self::assertSame(expected: $expected, actual: $this->refId(class: $class, ref: $ref));
		}

	}//end testReferences()

	/**
	 * Call `refId()` of a class.
	 *
	 * @param class-string $class The class.
	 * @param mixed        $ref   The stored reference.
	 *
	 * @return string The id.
	 */
	private function refId(string $class, mixed $ref): string {
		$reflection = new ReflectionClass($class);

		return $reflection->getMethod('refId')->invoke($reflection->newInstanceWithoutConstructor(), $ref);

	}//end refId()
}//end class
