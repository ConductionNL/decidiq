<?php

/**
 * Forwards the value migration's three operations to the real connection.
 *
 * Deliberately thin, and deliberately the ONLY part of this migration that a
 * decidesk unit test cannot reach: the app's unit environment has no
 * doctrine/dbal, so IDBConnection cannot be doubled here at all. Everything
 * that makes a decision lives behind ValueMigrationGateway instead, where it is
 * tested.
 *
 * @category  Repair
 * @package   OCA\Decidesk\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidesk\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * IDBConnection-backed gateway for the stored-value migration.
 */
class DbValueMigrationGateway implements ValueMigrationGateway {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection                     $db        Database connection.
	 * @param LoggerInterface                   $logger    Logger.
	 * @param RenameDutchDecideskValueDecisions $decisions Pure predicates.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RenameDutchDecideskValueDecisions $decisions = new RenameDutchDecideskValueDecisions(),
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function shardTables(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning('DbValueMigrationGateway: could not list tables.', ['exception' => $e->getMessage()]);
			return [];
		}

		return $this->decisions->column(rows: $rows, key: 'table_name');
	}//end shardTables()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	public function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning('DbValueMigrationGateway: could not read columns.', ['table' => $table, 'exception' => $e->getMessage()]);
			return [];
		}

		return $this->decisions->column(rows: $rows, key: 'column_name');
	}//end columnsOf()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @param string $old    Stored Dutch value.
	 * @param string $new    English replacement.
	 *
	 * @return int
	 */
	public function rewrite(string $table, string $column, string $old, string $new): int {
		$quote = fn (string $i): string => $this->db->getDatabasePlatform()->quoteSingleIdentifier($i);
		$sql = 'UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = ? WHERE ' . $quote($column) . ' = ?';

		try {
			return $this->db->executeStatement($sql, [$new, $old]);
		} catch (Exception $e) {
			$this->logger->warning('DbValueMigrationGateway: rewrite failed.', ['table' => $table, 'column' => $column, 'exception' => $e->getMessage()]);
			return 0;
		}
	}//end rewrite()
}//end class
