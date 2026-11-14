<?php

/**
 * Tests for SystemObjectRows: objects matching a filter as arrays, none on a failed read.
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
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-lapses-when-the-body-does-not-ratify-it-at-the-next-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use JsonSerializable;
use OCA\Decidiq\Service\SystemObjectRows;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\SystemObjectRows
 */
class SystemObjectRowsTest extends TestCase {

	/**
	 * The filter goes to the decidiq register in system context; entities become arrays, other values are dropped.
	 *
	 * @return void
	 */
	public function testMatchingObjectsAreReadAsArrays(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised object.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'm-1'];
			}
		};
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('findAll')->with(
			['filters' => ['register' => 'decidiq', 'schema' => 'meeting', 'body' => 'b-1']],
			false,
			false
		)->willReturn(['results' => [$entity, ['id' => 'm-2'], 'junk']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		self::assertSame(
			[['id' => 'm-1'], ['id' => 'm-2']],
			SystemObjectRows::read(objectService: $objects, logger: $logger, filters: ['schema' => 'meeting', 'body' => 'b-1'], warning: 'w')
		);
	}//end testMatchingObjectsAreReadAsArrays()

	/**
	 * A plain list without a results key is read as the rows.
	 *
	 * @return void
	 */
	public function testAPlainListIsReadAsTheRows(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([['id' => 'a'], ['id' => 'b']]);

		self::assertSame(
			[['id' => 'a'], ['id' => 'b']],
			SystemObjectRows::read(objectService: $objects, logger: $this->createMock(LoggerInterface::class), filters: [], warning: 'w')
		);
	}//end testAPlainListIsReadAsTheRows()

	/**
	 * A failed read gives no rows and logs the warning with the filter and the error.
	 *
	 * @return void
	 */
	public function testAFailedReadGivesNoRowsAndWarns(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: objects could not be read',
			['filters' => ['schema' => 'meeting'], 'error' => 'down']
		);

		self::assertSame(
			[],
			SystemObjectRows::read(objectService: $objects, logger: $logger, filters: ['schema' => 'meeting'], warning: 'Decidiq: objects could not be read')
		);
	}//end testAFailedReadGivesNoRowsAndWarns()
}//end class
