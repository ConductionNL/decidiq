<?php

/**
 * Characterisation test for normalize() of the reporting services.
 *
 * RegulatorExportService, GovernanceReportingService and
 * MultilingualReconciliationService each turn a findAll() result into a list
 * of plain row arrays through a private `normalize()`. This pins its outcomes
 * for each of them.
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

use JsonSerializable;
use OCA\Decidiq\Service\GovernanceReportingService;
use OCA\Decidiq\Service\MultilingualReconciliationService;
use OCA\Decidiq\Service\RegulatorExportService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins `normalize()` of every reporting service that carries it.
 */
class RowListNormalisationTest extends TestCase {

	/**
	 * The services whose normalize() is pinned.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function services(): array {
		return [
			'regulator export' => [RegulatorExportService::class],
			'governance reporting' => [GovernanceReportingService::class],
			'multilingual reconciliation' => [MultilingualReconciliationService::class],
		];

	}//end services()

	/**
	 * Entities serialise, arrays pass, other values drop out, keys are renumbered.
	 *
	 * @param class-string $class The service under test.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testRows(string $class): void {
		$entity = new ObjectEntity();
		$entity->setUuid('row-1');
		$entity->setObject(['title' => 'Row']);
		$scalar = new class implements JsonSerializable {
			/**
			 * A scalar serialisation.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';

			}//end jsonSerialize()
		};

		$rows = ['a' => $entity, 'b' => ['plain' => true], 'c' => 'text', 'd' => 3, 'e' => new stdClass(), 'f' => $scalar];
		self::assertSame(
			expected: [$entity->jsonSerialize(), ['plain' => true], ['scalar']],
			actual: $this->normalize(class: $class, rows: $rows)
		);
		self::assertSame(expected: [], actual: $this->normalize(class: $class, rows: null));
		self::assertSame(expected: [], actual: $this->normalize(class: $class, rows: []));
		self::assertSame(expected: [], actual: $this->normalize(class: $class, rows: ['x' => 1]));

	}//end testRows()

	/**
	 * Call the private `normalize()` of a service.
	 *
	 * @param class-string $class The service.
	 * @param mixed        $rows  The findAll() result.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function normalize(string $class, mixed $rows): array {
		$reflection = new ReflectionClass($class);

		return $reflection->getMethod('normalize')->invoke($reflection->newInstanceWithoutConstructor(), $rows);

	}//end normalize()
}//end class
