<?php

/**
 * Decidiq CsvCell
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The one rule for a CSV cell that a spreadsheet opens: inert, so it is
 * never read as a formula, also where a reader that splits on the `;` list
 * separator of nl-NL starts a cell, and quoted when a comma- or
 * semicolon-separated reader would split it (RFC 4180).
 * It holds no state.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
final class CsvCell {

	/**
	 * The cell inert, and quoted only when it holds a comma, semicolon,
	 * quote or line break.
	 *
	 * @param string $cell The cell text
	 *
	 * @return string
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function escape(string $cell): string {
		$cell = $this->inert(cell: $cell);
		if (strpbrk($cell, ",;\"\r\n") === false) {
			return $cell;
		}

		return $this->wrap(cell: $cell);
	}//end escape()

	/**
	 * The cell inert and always quoted.
	 *
	 * @param string $cell The cell text
	 *
	 * @return string
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function quote(string $cell): string {
		return $this->wrap(cell: $this->inert(cell: $cell));
	}//end quote()

	/**
	 * The cell between double quotes, with its own quotes doubled.
	 *
	 * @param string $cell The cell text
	 *
	 * @return string
	 */
	private function wrap(string $cell): string {
		return '"' . str_replace('"', '""', $cell) . '"';
	}//end wrap()

	/**
	 * The cell with an apostrophe wherever a spreadsheet would start reading
	 * a formula: at the start, and after each `;`, where a reader that splits
	 * on `;` starts a new cell whatever the quoting. A formula starts with =,
	 * +, -, @, a tab or a carriage return, also behind spaces or line feeds
	 * (and, after a `;`, quotes), which an import may trim.
	 *
	 * @param string $cell The cell text
	 *
	 * @return string
	 */
	private function inert(string $cell): string {
		$cell = (string)preg_replace('/;(?=[ \n"]*[=+\-@\t\r])/', ";'", $cell);
		if (strpbrk(substr(ltrim($cell, " \n"), 0, 1), "=+-@\t\r") !== false) {
			return "'" . $cell;
		}

		return $cell;
	}//end inert()
}//end class
