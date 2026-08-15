<?php

/**
 * Tests for the stored-enum-value migration's decisions.
 *
 * Decidesk's unit environment does not install doctrine/dbal, so
 * `createMock(IDBConnection)` cannot be generated — it fails on
 * `Doctrine\DBAL\ParameterType` while BUILDING the double, before a single
 * assertion runs. The database half of the step is therefore not reachable
 * from here, which is exactly why every decision it makes lives in a
 * collaborator that is.
 *
 * @category  Test
 * @package   OCA\Decidesk\Tests\Unit\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Repair;

use OCA\Decidesk\Repair\RenameDutchDecideskValueDecisions;
use OCA\Decidesk\Repair\RenameDutchDecideskValues;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The value map and the predicates that drive it.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidesk\Repair\RenameDutchDecideskValueDecisions
 * @covers \OCA\Decidesk\Repair\RenameDutchDecideskValues
 */
final class RenameDutchDecideskValuesTest extends TestCase {

	/**
	 * The predicates under test.
	 *
	 * @var RenameDutchDecideskValueDecisions
	 */
	private RenameDutchDecideskValueDecisions $decisions;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->decisions = new RenameDutchDecideskValueDecisions();

	}//end setUp()

	/**
	 * Property names snake down to the columns MagicMapper materialised.
	 *
	 * MagicMapper applies ONLY the ([a-z0-9])([A-Z]) boundary — no acronym rule.
	 * A column spelled any other way matches nothing and the migration is a
	 * silent no-op rather than an error.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testColumnForMirrorsMagicMapper(): void {
		self::assertSame('signature_status', $this->decisions->columnFor('signatureStatus'));
		self::assertSame('decision_category', $this->decisions->columnFor('decisionCategory'));
		self::assertSame('routing_advice', $this->decisions->columnFor('routingAdvice'));
		self::assertSame('status', $this->decisions->columnFor('status'));

		foreach (array_keys(RenameDutchDecideskValues::VALUE_MAP) as $property) {
			self::assertMatchesRegularExpression(
				'/^[a-z][a-z0-9_]*$/',
				$this->decisions->columnFor((string)$property),
				(string)$property
			);
		}

	}//end testColumnForMirrorsMagicMapper()

	/**
	 * A table only gets the rewrites for columns it actually has.
	 *
	 * Shard tables are per-schema, so most carry only a few of the mapped
	 * columns — and an UPDATE against a column the table lacks is an error.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testPlannedRewritesSkipColumnsTheTableLacks(): void {
		$planned = $this->decisions->plannedRewrites(
			['verdict' => ['afkeurend' => 'adverse', 'goedkeurend' => 'approving']],
			['verdict', 'name']
		);
		self::assertCount(2, $planned);
		self::assertSame('verdict', $planned[0]['column']);
		self::assertSame('afkeurend', $planned[0]['old']);
		self::assertSame('adverse', $planned[0]['new']);

		self::assertSame([], $this->decisions->plannedRewrites(
			['verdict' => ['afkeurend' => 'adverse']],
			['name']
		), 'a column the table lacks yields no work');

		self::assertSame([], $this->decisions->plannedRewrites([], ['verdict']));

	}//end testPlannedRewritesSkipColumnsTheTableLacks()

	/**
	 * Reading a result column tolerates a missing cell.
	 *
	 * A null must yield an empty string, not a TypeError inside a repair step
	 * where an exception aborts the upgrade.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testColumnReadIsDefensive(): void {
		self::assertSame(
			['a', '', 'b'],
			$this->decisions->column(
				[['table_name' => 'a'], ['table_name' => null], ['table_name' => 'b']],
				'table_name'
			)
		);
		self::assertSame([], $this->decisions->column([], 'table_name'));

	}//end testColumnReadIsDefensive()

	/**
	 * Every mapped value changes, and no target is also a source.
	 *
	 * A target that is also somebody's source means the ORDER of the map decides
	 * the result; a value mapping to itself is an UPDATE run for nothing.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testMapEntriesChangeSomethingAndDoNotChain(): void {
		$map = RenameDutchDecideskValues::VALUE_MAP;
		self::assertNotSame([], $map);

		foreach ($map as $property => $values) {
			$sources = array_keys($values);
			foreach ($values as $old => $new) {
				self::assertNotSame($old, $new, "`$old` on `$property` maps to itself");
				self::assertNotContains(
					$new,
					$sources,
					sprintf("target '%s' on '%s' is also a source", $new, $property)
				);
			}
		}

	}//end testMapEntriesChangeSomethingAndDoNotChain()

	/**
	 * The ORI vocabulary is NOT migrated.
	 *
	 * `Besluit`/`Vergadering`/`Verslag` are the ORI standard's terms and
	 * decidesk's own OriSerializer consumes them. A mapping is configuration.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testOriVocabularyIsNotMigrated(): void {
		$map = RenameDutchDecideskValues::VALUE_MAP;
		self::assertArrayNotHasKey('oriType', $map);

		$sources = [];
		foreach ($map as $values) {
			$sources = array_merge($sources, array_keys($values));
		}

		foreach (['Besluit', 'Vergadering', 'Verslag'] as $term) {
			self::assertNotContains($term, $sources, sprintf("'%s' is ORI vocabulary", $term));
		}

	}//end testOriVocabularyIsNotMigrated()

	/**
	 * Every Dutch legal term the migration replaces survives in l10n.
	 *
	 * This is the half that matters to a person: the DATA becomes English, and
	 * a Dutch-rendered UI must still say `Splitsingsakte`. If an entry is
	 * missing the term is simply gone from the interface.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testDutchLegalTermsSurviveInL10n(): void {
		$nl = (json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true) ?? []);
		$translations = ($nl['translations'] ?? []);

		foreach ([
			'Deed of division' => 'Splitsingsakte',
			'Articles of association' => 'Statuten',
			'By law' => 'Verordening',
			'Rules of procedure' => 'Reglement van orde',
			'Municipalities act' => 'Gemeentewet',
		] as $english => $dutch) {
			self::assertSame($dutch, ($translations[$english] ?? null), $english);
		}

	}//end testDutchLegalTermsSurviveInL10n()

	/**
	 * The class is a repair step and names itself.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the vocabulary migration.
	 */
	public function testIsARepairStepThatNamesItself(): void {
		$class = new ReflectionClass(RenameDutchDecideskValues::class);
		self::assertTrue($class->implementsInterface(\OCP\Migration\IRepairStep::class));
		self::assertStringContainsString('value', strtolower($class->newInstanceWithoutConstructor()->getName()));

	}//end testIsARepairStepThatNamesItself()
}//end class
