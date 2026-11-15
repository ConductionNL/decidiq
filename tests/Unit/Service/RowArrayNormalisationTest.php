<?php

/**
 * Characterisation test for toArray() of the quorum, process-template and QES code.
 *
 * QuorumVerificationService, ProcessTemplateService and QesGuard each turn an
 * ObjectService row into an array through a private `toArray(mixed $row)`: a
 * serialisable object serialises, an array passes through, anything else is
 * empty. This pins those outcomes for each of them.
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
use OCA\Decidiq\Lifecycle\QesGuard;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\QuorumVerificationService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins `toArray(mixed $row)` of the three classes that carry it.
 */
class RowArrayNormalisationTest extends TestCase {

	/**
	 * The classes whose toArray() is pinned.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function owners(): array {
		return [
			'quorum verification' => [QuorumVerificationService::class],
			'process template' => [ProcessTemplateService::class],
			'qes guard' => [QesGuard::class],
		];

	}//end owners()

	/**
	 * Serialisable objects serialise, arrays pass, the rest is empty.
	 *
	 * @param class-string $class The class under test.
	 *
	 * @return void
	 */
	#[DataProvider('owners')]
	public function testOutcomes(string $class): void {
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

		self::assertSame(expected: $entity->jsonSerialize(), actual: $this->toArray(class: $class, row: $entity));
		self::assertSame(expected: ['scalar'], actual: $this->toArray(class: $class, row: $scalar));
		self::assertSame(expected: ['k' => 'v'], actual: $this->toArray(class: $class, row: ['k' => 'v']));
		foreach ([null, false, 'text', 4, new stdClass()] as $row) {
			self::assertSame(expected: [], actual: $this->toArray(class: $class, row: $row));
		}

	}//end testOutcomes()

	/**
	 * Call the private `toArray()` of a class.
	 *
	 * @param class-string $class The class.
	 * @param mixed        $row   The row.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function toArray(string $class, mixed $row): array {
		$reflection = new ReflectionClass($class);

		return $reflection->getMethod('toArray')->invoke($reflection->newInstanceWithoutConstructor(), $row);

	}//end toArray()
}//end class
