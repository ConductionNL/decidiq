<?php

/**
 * Unit tests for SelectionCategoryReader: a schema without an enabled
 * archive block, without a category, or with a category whose row is
 * missing or malformed has no category, so nothing is routed on a guess.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Service\Records\SelectionCategoryReader;
use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading a schema's Selectielijst category.
 *
 * @covers \OCA\Decidiq\Service\Records\SelectionCategoryReader
 */
class SelectionCategoryReaderTest extends TestCase {

	/**
	 * The reader over a register holding this archive block and these rows.
	 *
	 * @param mixed $archive The ArchivalDossier archive block
	 * @param mixed $rows    The register's selectionLists
	 *
	 * @return SelectionCategoryReader
	 */
	private function reader(mixed $archive, mixed $rows): SelectionCategoryReader {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('mergedRegisterConfig')->willReturn(
			['components' => ['schemas' => ['ArchivalDossier' => ['archive' => $archive]], 'selectionLists' => $rows]]
		);

		return new SelectionCategoryReader(settings: $settings);
	}//end reader()

	/**
	 * The shipped shape: an enabled block naming a category with a row.
	 *
	 * @return void
	 */
	public function testAnEnabledCategoryWithARowIsRead(): void {
		$row = ['category' => '19.1', 'action' => 'vernietigen', 'retentionYears' => 5, 'description' => 'Ingetrokken voorstellen'];
		self::assertSame($row, $this->reader(archive: ['enabled' => true, 'classification' => '19.1'], rows: [$row])->forSchema(schemaKey: 'ArchivalDossier'));
	}//end testAnEnabledCategoryWithARowIsRead()

	/**
	 * Each way a category can be missing reads as none.
	 *
	 * @param mixed $archive The archive block
	 * @param mixed $rows    The selectionLists
	 *
	 * @return void
	 *
	 * @dataProvider missingCategories
	 */
	public function testAMissingOrMalformedCategoryIsNone(mixed $archive, mixed $rows): void {
		self::assertNull($this->reader(archive: $archive, rows: $rows)->forSchema(schemaKey: 'ArchivalDossier'));
	}//end testAMissingOrMalformedCategoryIsNone()

	/**
	 * The ways a category can be missing.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function missingCategories(): array {
		$row = ['category' => '2.1', 'action' => 'bewaren', 'description' => 'Raadsbesluiten'];
		return [
			'archive disabled' => [['enabled' => false, 'classification' => '2.1'], [$row]],
			'no archive block' => ['none', [$row]],
			'no category' => [['enabled' => true, 'classification' => ''], [$row]],
			'rows not a list' => [['enabled' => true, 'classification' => '2.1'], 'none'],
			'no row for the category' => [['enabled' => true, 'classification' => '3.1'], [$row, 'not a row']],
			'an unknown action' => [['enabled' => true, 'classification' => '2.1'], [['category' => '2.1', 'action' => 'archive']]],
		];
	}//end missingCategories()
}//end class
