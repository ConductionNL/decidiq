<?php

/**
 * Unit tests for SelectionCategoryReader's per-object reading: an object's
 * own category wins only when its schema points at it and the register ships
 * a row for it, otherwise the schema's category applies.
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
 * Tests for reading one object's Selectielijst category.
 *
 * @covers \OCA\Decidiq\Service\Records\SelectionCategoryReader
 */
class SelectionCategoryReaderObjectTest extends TestCase {

	/**
	 * The register's Selectielijst rows.
	 *
	 * @var array<int, mixed>
	 */
	private const ROWS = [
		['category' => '1.1', 'action' => 'bewaren', 'retentionYears' => 20, 'description' => 'Raadsbesluiten'],
		['category' => '11.1', 'action' => 'vernietigen', 'retentionYears' => 7, 'description' => 'Correspondentie'],
		['category' => '12.1', 'action' => 'vernietigen', 'retentionYears' => '5'],
		['category' => '13.1', 'action' => 'overbrengen'],
		'junk',
	];

	/**
	 * The reader over a register whose dossier schema has this archive block.
	 *
	 * @param mixed $archive The ArchivalDossier archive block
	 * @param mixed $rows    The register's selectionLists
	 *
	 * @return SelectionCategoryReader
	 */
	private function reader(mixed $archive, mixed $rows = self::ROWS): SelectionCategoryReader {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('mergedRegisterConfig')->willReturn(
			['components' => ['schemas' => ['ArchivalDossier' => ['archive' => $archive]], 'selectionLists' => $rows]]
		);

		return new SelectionCategoryReader(settings: $settings);
	}//end reader()

	/**
	 * The archive block of the shipped dossier schema.
	 *
	 * @var array<string, mixed>
	 */
	private const ARCHIVE = ['enabled' => true, 'classification' => '1.1', 'classificationProperty' => 'selectionCategory'];

	/**
	 * An object's own category with a row wins, and says it overrides the schema's.
	 *
	 * @return void
	 */
	public function testAnObjectsOwnCategoryWins(): void {
		self::assertSame(
			['category' => '11.1', 'action' => 'vernietigen', 'retentionYears' => 7, 'description' => 'Correspondentie', 'overridden' => true],
			$this->reader(archive: self::ARCHIVE)->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '11.1'])
		);
	}//end testAnObjectsOwnCategoryWins()

	/**
	 * Without an own category, with the schema's own, or with an unknown one, the schema's applies.
	 *
	 * @return void
	 */
	public function testOtherwiseTheSchemasCategoryApplies(): void {
		$schemaRow = ['category' => '1.1', 'action' => 'bewaren', 'retentionYears' => 20, 'description' => 'Raadsbesluiten', 'overridden' => false];
		$reader = $this->reader(archive: self::ARCHIVE);

		self::assertSame($schemaRow, $reader->forObject(schemaKey: 'ArchivalDossier', object: []));
		self::assertSame($schemaRow, $reader->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '1.1']));
		self::assertSame($schemaRow, $reader->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '99.9']));
		self::assertSame($schemaRow, $reader->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => 11]));
		self::assertSame($schemaRow, $reader->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '13.1']));
	}//end testOtherwiseTheSchemasCategoryApplies()

	/**
	 * Without a pointer the object's own field is ignored.
	 *
	 * @return void
	 */
	public function testWithoutAPointerTheObjectsFieldIsIgnored(): void {
		$read = $this->reader(archive: ['enabled' => true, 'classification' => '1.1'])
			->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '11.1']);

		self::assertSame('1.1', $read['category']);
		self::assertFalse($read['overridden']);
	}//end testWithoutAPointerTheObjectsFieldIsIgnored()

	/**
	 * An own category still applies when the schema archives under none.
	 *
	 * @return void
	 */
	public function testAnOwnCategoryAppliesWithoutASchemaCategory(): void {
		$reader = $this->reader(archive: ['enabled' => false, 'classificationProperty' => 'selectionCategory']);

		self::assertSame('11.1', $reader->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '11.1'])['category']);
		self::assertNull($reader->forObject(schemaKey: 'ArchivalDossier', object: []));
		self::assertNull($reader->forObject(schemaKey: 'Unknown', object: ['selectionCategory' => '11.1']));
	}//end testAnOwnCategoryAppliesWithoutASchemaCategory()

	/**
	 * The pointer is read only when it is a non-empty string.
	 *
	 * @return void
	 */
	public function testTheOverridePropertyIsANonEmptyString(): void {
		self::assertSame('selectionCategory', $this->reader(archive: self::ARCHIVE)->overrideProperty(schemaKey: 'ArchivalDossier'));
		self::assertNull($this->reader(archive: ['classificationProperty' => ''])->overrideProperty(schemaKey: 'ArchivalDossier'));
		self::assertNull($this->reader(archive: ['classificationProperty' => 7])->overrideProperty(schemaKey: 'ArchivalDossier'));
		self::assertNull($this->reader(archive: 'none')->overrideProperty(schemaKey: 'ArchivalDossier'));
		self::assertNull($this->reader(archive: self::ARCHIVE)->overrideProperty(schemaKey: 'Unknown'));
	}//end testTheOverridePropertyIsANonEmptyString()

	/**
	 * A category is known only when the register ships a row with a keep or destroy action.
	 *
	 * @return void
	 */
	public function testACategoryIsKnownOnlyWithAUsableRow(): void {
		$reader = $this->reader(archive: self::ARCHIVE);

		self::assertTrue($reader->isKnown(category: '1.1'));
		self::assertTrue($reader->isKnown(category: '12.1'));
		self::assertFalse($reader->isKnown(category: '13.1'));
		self::assertFalse($reader->isKnown(category: '99.9'));
		self::assertFalse($this->reader(archive: self::ARCHIVE, rows: 'none')->isKnown(category: '1.1'));
	}//end testACategoryIsKnownOnlyWithAUsableRow()

	/**
	 * A row whose retention is not an integer reads as no retention, and no description as empty.
	 *
	 * @return void
	 */
	public function testANonIntegerRetentionReadsAsNone(): void {
		$read = $this->reader(archive: self::ARCHIVE)->forObject(schemaKey: 'ArchivalDossier', object: ['selectionCategory' => '12.1']);

		self::assertSame(['category' => '12.1', 'action' => 'vernietigen', 'retentionYears' => null, 'description' => '', 'overridden' => true], $read);
	}//end testANonIntegerRetentionReadsAsNone()
}//end class
