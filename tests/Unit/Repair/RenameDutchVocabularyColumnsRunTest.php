<?php

/**
 * Behaviour tests for RenameDutchVocabularyColumns::run().
 *
 * Drives the repair step end to end against a mocked IDBConnection: register
 * resolution, shard-table discovery, column inspection and the exact DDL/DML
 * each column state produces, plus every failure path that must skip rather
 * than abort.
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
use OCA\Decidiq\Repair\SqlIdentifierQuoter;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Repair\RenameDutchVocabularyColumns
 * @uses   \OCA\Decidiq\Repair\SqlIdentifierQuoter
 */
class RenameDutchVocabularyColumnsRunTest extends TestCase {
	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mocked repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput $output;

	/**
	 * Messages passed to IOutput::info().
	 *
	 * @var array<int, string>
	 */
	private array $infos = [];

	/**
	 * Values bound to each prepared statement, in prepare order.
	 *
	 * @var array<int, array{sql: string, bound: array<string, mixed>}>
	 */
	private array $prepared = [];

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(function (string $message): void {
			$this->infos[] = $message;
		});

	}//end setUp()

	/**
	 * Build the step under test.
	 *
	 * @return RenameDutchVocabularyColumns
	 */
	private function step(): RenameDutchVocabularyColumns {
		return new RenameDutchVocabularyColumns($this->db, $this->logger, new SqlIdentifierQuoter());

	}//end step()

	/**
	 * A result whose fetch() yields the given rows, then false.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to yield.
	 *
	 * @return IResult&MockObject
	 */
	private function rows(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->expects(self::once())->method('closeCursor')->willReturn(true);
		return $result;

	}//end rows()

	/**
	 * Make the register lookup return the given id.
	 *
	 * @param mixed $registerId Value fetchOne() returns.
	 *
	 * @return void
	 */
	private function registerResolvesTo($registerId): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($registerId);
		$this->db->expects(self::once())
			->method('executeQuery')
			->with('SELECT id FROM `*PREFIX*openregister_registers` WHERE slug = ?', ['decidiq'])
			->willReturn($result);

	}//end registerResolvesTo()

	/**
	 * Wire prepare() to serve the table listing and per-table column lists.
	 *
	 * @param array<int, string>                    $tables  Table names information_schema returns.
	 * @param array<string, array<int, string>|null> $columns Columns per table; null makes execute() throw.
	 *
	 * @return void
	 */
	private function schemaIs(array $tables, array $columns): void {
		$this->db->method('prepare')->willReturnCallback(
			function (string $sql) use ($tables, $columns): IPreparedStatement {
				$index = count($this->prepared);
				$this->prepared[$index] = ['sql' => $sql, 'bound' => []];

				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('bindValue')->willReturnCallback(
					function ($param, $value) use ($index): bool {
						$this->prepared[$index]['bound'][$param] = $value;
						return true;
					}
				);
				$stmt->method('execute')->willReturnCallback(
					function () use ($index, $sql, $tables, $columns): IResult {
						if (str_contains($sql, 'information_schema.tables') === true) {
							return $this->rows(array_map(static fn (string $t): array => ['table_name' => $t], $tables));
						}

						$table = $this->prepared[$index]['bound']['table'];
						if (($columns[$table] ?? null) === null) {
							throw new DbException('columns unavailable');
						}

						return $this->rows(array_map(static fn (string $c): array => ['column_name' => $c], $columns[$table]));
					}
				);
				return $stmt;
			}
		);

	}//end schemaIs()

	/**
	 * Record every executeStatement() call, optionally failing some.
	 *
	 * @param array<int, string> $failing SQL statements that should throw.
	 *
	 * @return array<int, string> Reference-captured list (read after run()).
	 */
	private function &captureStatements(array $failing=[]): array {
		$executed = [];
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql) use (&$executed, $failing): int {
				$executed[] = $sql;
				if (in_array($sql, $failing, true) === true) {
					throw new DbException('refused');
				}

				return 1;
			}
		);
		return $executed;

	}//end captureStatements()

	/**
	 * A register lookup failure is logged and the step does nothing.
	 *
	 * @return void
	 */
	public function testRegisterLookupFailureSkipsTheStep(): void {
		$this->db->method('executeQuery')->willThrowException(new DbException('no table'));
		$this->db->expects(self::never())->method('prepare');
		$this->db->expects(self::never())->method('executeStatement');
		$this->logger->expects(self::once())
			->method('warning')
			->with(
				'RenameDutchVocabularyColumns: could not resolve the decidesk register; skipping.',
				['exception' => 'no table']
			);

		$this->step()->run($this->output);

		self::assertSame(
			['RenameDutchVocabularyColumns: no decidesk shard tables on this install; nothing to do.'],
			$this->infos
		);

	}//end testRegisterLookupFailureSkipsTheStep()

	/**
	 * An install without the register does nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testMissingRegisterIsANoOp(): void {
		$this->registerResolvesTo(false);
		$this->db->expects(self::never())->method('prepare');
		$this->db->expects(self::never())->method('executeStatement');
		$this->logger->expects(self::never())->method('warning');

		$this->step()->run($this->output);

		self::assertSame(
			['RenameDutchVocabularyColumns: no decidesk shard tables on this install; nothing to do.'],
			$this->infos
		);

	}//end testMissingRegisterIsANoOp()

	/**
	 * A failure listing tables is logged and the step does nothing.
	 *
	 * @return void
	 */
	public function testTableListingFailureSkipsTheStep(): void {
		$this->registerResolvesTo('18');
		$this->db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));
		$this->db->expects(self::never())->method('executeStatement');
		$this->logger->expects(self::once())
			->method('warning')
			->with(
				'RenameDutchVocabularyColumns: could not list tables; skipping.',
				['exception' => 'no information_schema']
			);

		$this->step()->run($this->output);

		self::assertSame(
			['RenameDutchVocabularyColumns: no decidesk shard tables on this install; nothing to do.'],
			$this->infos
		);

	}//end testTableListingFailureSkipsTheStep()

	/**
	 * Only the register's own numeric shards are touched; a missing English
	 * column is renamed, an existing one is back-filled, the rest is skipped.
	 *
	 * @return void
	 */
	public function testRenamesOrBackFillsOnlyTheRegistersShards(): void {
		$this->registerResolvesTo('18');
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$this->schemaIs(
			[
				'oc_openregister_table_18_85',
				'oc_openregister_table_17_85',
				'oc_openregister_table_18_85_backup',
				'oc_openregister_table_18_90',
			],
			[
				'oc_openregister_table_18_85' => ['id', 'onderwerp', 'publicatiedatum', 'publication_date'],
				'oc_openregister_table_18_90' => ['id', 'naam', 'subject'],
			]
		);
		$executed = &$this->captureStatements();
		$this->logger->expects(self::never())->method('warning');

		$this->step()->run($this->output);

		self::assertSame(
			[
				'UPDATE `oc_openregister_table_18_85` SET `publication_date` = `publicatiedatum`'
					. ' WHERE `publication_date` IS NULL AND `publicatiedatum` IS NOT NULL',
				'ALTER TABLE `oc_openregister_table_18_85` RENAME COLUMN `onderwerp` TO `subject`',
				'ALTER TABLE `oc_openregister_table_18_90` RENAME COLUMN `naam` TO `name`',
			],
			$executed
		);
		self::assertSame(
			[
				'RenameDutchVocabularyColumns: 2 column(s) renamed, 1 column(s) back-filled,'
					. ' across 2 decidesk shard table(s).',
			],
			$this->infos
		);

		// The table query anchors on the escaped marker; each column query on its table.
		self::assertStringContainsString('information_schema.tables', $this->prepared[0]['sql']);
		self::assertSame(['pattern' => '%openregister\_table\_%'], $this->prepared[0]['bound']);
		self::assertSame(['table' => 'oc_openregister_table_18_85'], $this->prepared[1]['bound']);
		self::assertSame(['table' => 'oc_openregister_table_18_90'], $this->prepared[2]['bound']);
		self::assertCount(3, $this->prepared);

	}//end testRenamesOrBackFillsOnlyTheRegistersShards()

	/**
	 * Identifiers are quoted for the active platform.
	 *
	 * @return void
	 */
	public function testQuotesIdentifiersForPostgres(): void {
		$this->registerResolvesTo(7);
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$this->schemaIs(
			['oc_openregister_table_7_3'],
			['oc_openregister_table_7_3' => ['fractie']]
		);
		$executed = &$this->captureStatements();

		$this->step()->run($this->output);

		self::assertSame(
			['ALTER TABLE "oc_openregister_table_7_3" RENAME COLUMN "fractie" TO "political_group"'],
			$executed
		);

	}//end testQuotesIdentifiersForPostgres()

	/**
	 * A failed statement is logged, not counted, and does not stop the run.
	 *
	 * @return void
	 */
	public function testFailedStatementIsLoggedAndNotCounted(): void {
		$this->registerResolvesTo('18');
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$this->schemaIs(
			['oc_openregister_table_18_1'],
			['oc_openregister_table_18_1' => ['onderwerp', 'toelichting', 'description', 'omschrijving']]
		);
		$failing = 'ALTER TABLE `oc_openregister_table_18_1` RENAME COLUMN `onderwerp` TO `subject`';
		$backFill = 'UPDATE `oc_openregister_table_18_1` SET `description` = `omschrijving`'
			. ' WHERE `description` IS NULL AND `omschrijving` IS NOT NULL';
		$executed = &$this->captureStatements([$failing, $backFill]);
		$this->logger->expects(self::exactly(2))
			->method('warning')
			->with(
				'RenameDutchVocabularyColumns: statement failed; leaving the column as it was.',
				self::callback(static fn (array $ctx): bool => in_array($ctx['sql'], [$failing, $backFill], true) && $ctx['exception'] === 'refused')
			);

		$this->step()->run($this->output);

		self::assertSame(
			[
				$failing,
				'ALTER TABLE `oc_openregister_table_18_1` RENAME COLUMN `toelichting` TO `notes`',
				$backFill,
			],
			$executed
		);
		self::assertSame(
			[
				'RenameDutchVocabularyColumns: 1 column(s) renamed, 0 column(s) back-filled,'
					. ' across 1 decidesk shard table(s).',
			],
			$this->infos
		);

	}//end testFailedStatementIsLoggedAndNotCounted()

	/**
	 * A table whose columns cannot be read is skipped; the others still migrate.
	 *
	 * @return void
	 */
	public function testUnreadableTableIsSkipped(): void {
		$this->registerResolvesTo('18');
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$this->schemaIs(
			['oc_openregister_table_18_1', 'oc_openregister_table_18_2'],
			['oc_openregister_table_18_1' => null, 'oc_openregister_table_18_2' => ['', 'vraag']]
		);
		$executed = &$this->captureStatements();
		$this->logger->expects(self::once())
			->method('warning')
			->with(
				'RenameDutchVocabularyColumns: could not read columns; skipping table.',
				['table' => 'oc_openregister_table_18_1', 'exception' => 'columns unavailable']
			);

		$this->step()->run($this->output);

		self::assertSame(
			['ALTER TABLE `oc_openregister_table_18_2` RENAME COLUMN `vraag` TO `question`'],
			$executed
		);
		self::assertSame(
			[
				'RenameDutchVocabularyColumns: 1 column(s) renamed, 0 column(s) back-filled,'
					. ' across 2 decidesk shard table(s).',
			],
			$this->infos
		);

	}//end testUnreadableTableIsSkipped()
}//end class
