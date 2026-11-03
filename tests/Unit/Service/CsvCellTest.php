<?php

/**
 * Tests for CsvCell.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\CsvCell;
use PHPUnit\Framework\TestCase;

/**
 * The CSV cell rule: inert for spreadsheets, also where a semicolon-separated
 * reader starts a cell, and quoted when a reader would split it.
 *
 * @covers \OCA\Decidiq\Service\CsvCell
 */
class CsvCellTest extends TestCase {

	/**
	 * Each cell escapes as expected.
	 *
	 * @param string $cell     The cell text
	 * @param string $expected The escaped cell
	 *
	 * @dataProvider cells
	 *
	 * @return void
	 */
	public function testEscape(string $cell, string $expected): void {
		self::assertSame($expected, (new CsvCell())->escape(cell: $cell));
	}//end testEscape()

	/**
	 * A quoted cell is always between quotes and still inert.
	 *
	 * @return void
	 */
	public function testQuote(): void {
		self::assertSame('"o-1|o-2"', (new CsvCell())->quote(cell: 'o-1|o-2'));
		self::assertSame('""', (new CsvCell())->quote(cell: ''));
		self::assertSame("\"'=1+1;x\"", (new CsvCell())->quote(cell: '=1+1;x'));
		self::assertSame("\"o-1;'=2\"", (new CsvCell())->quote(cell: 'o-1;=2'));
		self::assertSame('"say ""hi"""', (new CsvCell())->quote(cell: 'say "hi"'));
	}//end testQuote()

	/**
	 * The cells: plain, separators, quotes, line breaks and formula triggers.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cells(): array {
		return [
			'empty'                 => ['', ''],
			'plain'                 => ['plain', 'plain'],
			'inner space'           => ['a b', 'a b'],
			'leading space'         => [' plain', ' plain'],
			'comma'                 => ['a,b', '"a,b"'],
			'semicolon'             => ['a;b', '"a;b"'],
			'semicolon formula'     => ['x;=1+1;', "\"x;'=1+1;\""],
			'semicolon then space'  => ['x; -1', "\"x;' -1\""],
			'semicolon then quote'  => ['x;"=1"', "\"x;'\"\"=1\"\"\""],
			'two semicolons'        => ['a;@b;+c;d', "\"a;'@b;'+c;d\""],
			'semicolon then text'   => ['a; b', '"a; b"'],
			'leading and semicolon' => ['=a;=b', "\"'=a;'=b\""],
			'quote'                 => ['say "hi"', '"say ""hi"""'],
			'line feed'             => ["a\nb", "\"a\nb\""],
			'lone carriage return'  => ["a\rb", "\"a\rb\""],
			'leading line feed'     => ["\nplain", "\"\nplain\""],
			'equals'                => ['=1+1', "'=1+1"],
			'plus'                  => ['+1', "'+1"],
			'minus'                 => ['-1', "'-1"],
			'at'                    => ['@SUM(A1)', "'@SUM(A1)"],
			'tab'                   => ["\tx", "'\tx"],
			'carriage return'       => ["\rx", "\"'\rx\""],
			'space then equals'     => [' =1+1', "' =1+1"],
			'spaces then minus'     => ['  -1', "'  -1"],
			'line feed then at'     => ["\n@x", "\"\n'@x\""],
			'inner line feed'       => ["a\n=1", "\"a\n'=1\""],
			'inner carriage return' => ["a\r+1", "\"a\r'+1\""],
			'inner crlf'            => ["a\r\n-1", "\"a\r\n'-1\""],
			'semicolon line feed'   => ["a;\n=1", "\"a;\n'=1\""],
			'space then tab'        => [" \tx", "' \tx"],
			'formula and separator' => ['=HYPERLINK("https://evil.example","open")', "\"'=HYPERLINK(\"\"https://evil.example\"\",\"\"open\"\")\""],
			'trigger not leading'   => ['a=1', 'a=1'],
			'negative number'       => ['-5', "'-5"],
			'phone number'          => ['+31 6 12345678', "'+31 6 12345678"],
			'list item after break' => ["a\n- b", "\"a\n'- b\""],
		];
	}//end cells()
}//end class
