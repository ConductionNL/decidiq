<?php

/**
 * Unit tests for DbValueMigrationGateway.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\DbValueMigrationGateway;
use OCA\Decidiq\Repair\ValueMigrationGateway;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The real database gateway behind RenameDutchDecidiqValues: listing shard
 * tables, reading their columns and rewriting values, each defensive so a
 * repair step never aborts an upgrade.
 *
 * @covers \OCA\Decidiq\Repair\DbValueMigrationGateway
 * @uses   \OCA\Decidiq\Repair\RenameDutchDecidiqValueDecisions
 * @uses   \OCA\Decidiq\Repair\SqlIdentifierQuoter
 */
class DbValueMigrationGatewayTest extends TestCase {
	/**
	 * Database connection mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Subject under test.
	 *
	 * @var DbValueMigrationGateway
	 */
	private DbValueMigrationGateway $gateway;

	/**
	 * Build the gateway with the real decisions and quoter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db      = $this->createMock(IDBConnection::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->gateway = new DbValueMigrationGateway(db: $this->db, logger: $this->logger);
	}//end setUp()

	/**
	 * Build a prepared statement whose execution yields the given rows and
	 * expects the cursor to be closed.
	 *
	 * @param string $param Expected bound parameter name
	 * @param string $value Expected bound value
	 * @param array  $rows  Rows returned by fetchAll()
	 *
	 * @return IPreparedStatement&MockObject
	 */
	private function statement(string $param, string $value, array $rows): IPreparedStatement&MockObject {
		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetchAll')->willReturn($rows);
		$result->expects($this->once())->method('closeCursor');

		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->expects($this->once())->method('bindValue')->with($param, $value);
		$stmt->expects($this->once())->method('execute')->willReturn($result);
		return $stmt;
	}//end statement()

	/**
	 * The gateway satisfies the contract the repair step depends on.
	 *
	 * @return void
	 */
	public function testImplementsTheGatewayContract(): void {
		$this->assertInstanceOf(ValueMigrationGateway::class, $this->gateway);
	}//end testImplementsTheGatewayContract()

	/**
	 * Shard tables are listed by the escaped OpenRegister table pattern.
	 *
	 * @return void
	 */
	public function testShardTablesListsMatchingTableNames(): void {
		$stmt = $this->statement(
			'pattern',
			'%openregister\_table\_%',
			[['table_name' => 'oc_openregister_table_1_2'], ['table_name' => 'oc_openregister_table_1_3'], []]
		);
		$this->db->expects($this->once())
			->method('prepare')
			->with('SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern')
			->willReturn($stmt);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			['oc_openregister_table_1_2', 'oc_openregister_table_1_3', ''],
			$this->gateway->shardTables()
		);
	}//end testShardTablesListsMatchingTableNames()

	/**
	 * A failure while listing tables is logged and yields no tables.
	 *
	 * @return void
	 */
	public function testShardTablesFailureIsLoggedAndEmpty(): void {
		$this->db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'DbValueMigrationGateway: could not list tables.',
				['exception' => 'no information_schema']
			);

		$this->assertSame([], $this->gateway->shardTables());
	}//end testShardTablesFailureIsLoggedAndEmpty()

	/**
	 * Columns are read for the named table.
	 *
	 * @return void
	 */
	public function testColumnsOfReadsTheTablesColumns(): void {
		$stmt = $this->statement(
			'table',
			'oc_openregister_table_1_2',
			[['column_name' => 'status'], ['column_name' => 'verdict']]
		);
		$this->db->expects($this->once())
			->method('prepare')
			->with('SELECT column_name FROM information_schema.columns WHERE table_name = :table')
			->willReturn($stmt);

		$this->assertSame(['status', 'verdict'], $this->gateway->columnsOf('oc_openregister_table_1_2'));
	}//end testColumnsOfReadsTheTablesColumns()

	/**
	 * A failure while reading columns is logged with the table and yields none.
	 *
	 * @return void
	 */
	public function testColumnsOfFailureIsLoggedWithTable(): void {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willThrowException(new \RuntimeException('denied'));
		$this->db->method('prepare')->willReturn($stmt);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'DbValueMigrationGateway: could not read columns.',
				['table' => 't1', 'exception' => 'denied']
			);

		$this->assertSame([], $this->gateway->columnsOf('t1'));
	}//end testColumnsOfFailureIsLoggedWithTable()

	/**
	 * On PostgreSQL identifiers are double-quoted and the new value binds first.
	 *
	 * @return void
	 */
	public function testRewriteQuotesForPostgresAndReturnsAffectedRows(): void {
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$this->db->expects($this->once())
			->method('executeStatement')
			->with(
				'UPDATE "oc_openregister_table_1_2" SET "status" = ? WHERE "status" = ?',
				['determined', 'vastgesteld']
			)
			->willReturn(4);

		$this->assertSame(4, $this->gateway->rewrite('oc_openregister_table_1_2', 'status', 'vastgesteld', 'determined'));
	}//end testRewriteQuotesForPostgresAndReturnsAffectedRows()

	/**
	 * On MySQL identifiers are backtick-quoted.
	 *
	 * @return void
	 */
	public function testRewriteQuotesWithBackticksOnMysql(): void {
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$this->db->expects($this->once())
			->method('executeStatement')
			->with('UPDATE `t` SET `c` = ? WHERE `c` = ?', ['new', 'old'])
			->willReturn(0);

		$this->assertSame(0, $this->gateway->rewrite('t', 'c', 'old', 'new'));
	}//end testRewriteQuotesWithBackticksOnMysql()

	/**
	 * A database error during the rewrite is logged and counts as zero rows.
	 *
	 * @return void
	 */
	public function testRewriteDatabaseErrorIsLoggedAsZero(): void {
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$this->db->method('executeStatement')->willThrowException(new DbException('locked'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'DbValueMigrationGateway: rewrite failed.',
				['table' => 't', 'column' => 'c', 'exception' => 'locked']
			);

		$this->assertSame(0, $this->gateway->rewrite('t', 'c', 'old', 'new'));
	}//end testRewriteDatabaseErrorIsLoggedAsZero()
}//end class
