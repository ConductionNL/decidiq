<?php

/**
 * Unit tests for RenameDutchVocabularyColumns.
 *
 * Covers the decision that determines which shard tables the migration touches,
 * plus the mapping invariants the step relies on.
 *
 * The DDL/DML paths are deliberately not unit-tested: they need a live
 * database. What IS testable in isolation is the logic deciding which tables
 * are in scope, and that is what these tests pin.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\RenameDutchVocabularyColumns;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\Decidiq\Repair\RenameDutchVocabularyColumns
 */
class RenameDutchVocabularyColumnsTest extends TestCase {
	/**
	 * The step under test.
	 *
	 * @var RenameDutchVocabularyColumns
	 */
	private RenameDutchVocabularyColumns $step;

	/**
	 * Build the step WITHOUT running its constructor.
	 *
	 * The methods under test are pure — they read neither $db nor $logger — so
	 * no collaborators are needed, and mocking IDBConnection can drag in
	 * Doctrine types the unit environment does not install.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->step = (new ReflectionClass(RenameDutchVocabularyColumns::class))->newInstanceWithoutConstructor();

	}//end setUp()

	/**
	 * Invoke a private method on the step.
	 *
	 * @param string $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$m = new ReflectionMethod(RenameDutchVocabularyColumns::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->step, $args);
	}//end call()

	/**
	 * Read a private constant off the step.
	 *
	 * @param string $name Constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name) {
		return (new ReflectionClass(RenameDutchVocabularyColumns::class))->getConstant($name);
	}//end constant()

	/**
	 * A shard of the decidesk register is matched.
	 *
	 * @return void
	 */
	public function testMatchesShardOfTheRegister(): void {
		self::assertTrue($this->call('isShardOfRegister', ['oc_openregister_table_18_85', 'openregister_table_18_']));

	}//end testMatchesShardOfTheRegister()

	/**
	 * Another register's shard is NOT matched.
	 *
	 * The step is scoped by register precisely because several of these words
	 * are not unique to decidesk — procest also stores `onderwerp`. A step that
	 * scanned every shard table for a matching column would migrate another
	 * app's data as a side effect.
	 *
	 * @return void
	 */
	public function testDoesNotMatchAnotherRegistersShard(): void {
		self::assertFalse($this->call('isShardOfRegister', ['oc_openregister_table_17_85', 'openregister_table_18_']));
		self::assertFalse($this->call('isShardOfRegister', ['oc_openregister_table_180_85', 'openregister_table_18_']));

	}//end testDoesNotMatchAnotherRegistersShard()

	/**
	 * A derived or non-shard table sharing the marker is left alone.
	 *
	 * This is what the digits-only suffix check guards. It is NOT what stops
	 * register 18 matching register 180 — the marker already ends in '_', so
	 * that collision cannot occur.
	 *
	 * @return void
	 */
	public function testDoesNotMatchDerivedOrNonShardTables(): void {
		$marker = 'openregister_table_18_';
		self::assertFalse($this->call('isShardOfRegister', ['oc_openregister_table_18_85_backup', $marker]));
		self::assertFalse($this->call('isShardOfRegister', ['oc_openregister_table_18_audit', $marker]));
		self::assertFalse($this->call('isShardOfRegister', ['oc_openregister_registers', $marker]));

	}//end testDoesNotMatchDerivedOrNonShardTables()

	/**
	 * Every destination is snake_case, never camelCase.
	 *
	 * MagicMapper stores `publicationDate` as `publication_date`, and its
	 * de-duplication path DROPS a camelCase column whose snake_case twin
	 * exists — so a camelCase destination would be deleted on the next sync.
	 *
	 * @return void
	 */
	public function testEveryDestinationIsSnakeCase(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertIsArray($map);
		foreach ($map as $old => $new) {
			self::assertSame(
				strtolower($new),
				$new,
				"Destination '$new' (from '$old') must be snake_case, not camelCase"
			);
		}

	}//end testEveryDestinationIsSnakeCase()

	/**
	 * `toelichting` maps to `notes`, NOT to `description`.
	 *
	 * The two co-occur in four schemas fleet-wide, including decidesk's own
	 * Geschenk, so they are distinct concepts. Collapsing them would produce a
	 * duplicate destination and silently overwrite one value with the other.
	 *
	 * @return void
	 */
	public function testToelichtingIsNotesNotDescription(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertSame('notes', $map['toelichting']);
		self::assertSame('description', $map['omschrijving']);

	}//end testToelichtingIsNotesNotDescription()

	/**
	 * No two Dutch columns map to the same English name.
	 *
	 * This step has no collision guard, so it is correct only while the map
	 * stays injective. If a later edit introduces a duplicate destination the
	 * step would silently overwrite one value with another.
	 *
	 * @return void
	 */
	public function testColumnMapIsInjective(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertSame(
			count($map),
			count(array_unique(array_values($map))),
			'Two Dutch columns map to the same English name; add a collision guard first'
		);

	}//end testColumnMapIsInjective()

	/**
	 * The step reports a human-readable name.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		self::assertNotSame('', $this->step->getName());

	}//end testGetName()
}//end class
