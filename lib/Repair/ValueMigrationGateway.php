<?php

/**
 * The three database operations the value migration needs, and nothing else.
 *
 * A narrow port, introduced for a reason specific to this app: decidesk's unit
 * environment does not install doctrine/dbal, so `createMock(IDBConnection)`
 * fails while BUILDING the double on `Doctrine\DBAL\ParameterType` — before a
 * single assertion runs. Depending on the whole connection therefore makes the
 * step untestable here by construction, and an untestable repair step is one
 * that gets shipped on the strength of having been read.
 *
 * With this interface the step is driven by a three-method fake, and the only
 * thing left unreachable is the adapter that forwards to IDBConnection.
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

/**
 * Database access for the stored-value migration.
 */
interface ValueMigrationGateway {

	/**
	 * The OpenRegister shard tables present on this install.
	 *
	 * @return array<int, string> Table names, empty when none or unreadable.
	 *
	 * @spec exclude Seam for the Dutch-to-English vocabulary migration; no
	 *  canonical spec covers it and it declares no business rule.
	 */
	public function shardTables(): array;

	/**
	 * The column names of one table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string> Column names, empty when unreadable.
	 *
	 * @spec exclude Seam for the Dutch-to-English vocabulary migration; no
	 *  canonical spec covers it and it declares no business rule.
	 */
	public function columnsOf(string $table): array;

	/**
	 * Replace one stored value in one column.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @param string $old    The stored Dutch value.
	 * @param string $new    The English replacement.
	 *
	 * @return int Rows affected; 0 when the statement failed.
	 *
	 * @spec exclude Seam for the Dutch-to-English vocabulary migration; no
	 *  canonical spec covers it and it declares no business rule.
	 */
	public function rewrite(string $table, string $column, string $old, string $new): int;
}//end interface
