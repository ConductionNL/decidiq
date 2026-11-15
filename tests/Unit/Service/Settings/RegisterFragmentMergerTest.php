<?php

/**
 * Unit tests for RegisterFragmentMerger.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Settings;

use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the register fragment merger.
 *
 * @covers \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 */
class RegisterFragmentMergerTest extends TestCase {

	/**
	 * A merger used twice answers the second run with that run's signature only.
	 *
	 * @return void
	 */
	public function testEachRunStartsWithAnEmptySignature(): void {
		$dir = sys_get_temp_dir() . '/decidiq-fragment-merger-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/10-a.json', '{"a":1}');

		try {
			$merger = new RegisterFragmentMerger();
			$first  = $merger->mergeDirectory(configData: [], fragmentDir: $dir);
			$second = $merger->mergeDirectory(configData: [], fragmentDir: $dir);
		} finally {
			unlink($dir . '/10-a.json');
			rmdir($dir);
		}

		self::assertSame([['a' => 1], '10-a.json:' . md5('{"a":1}') . ';'], $first);
		self::assertSame($first, $second);
	}//end testEachRunStartsWithAnEmptySignature()

	/**
	 * A fragment that cannot be read is skipped: it neither changes the configuration nor enters the signature.
	 *
	 * A dangling `*.json` link is matched by the directory scan but cannot be
	 * opened, which is the unreadable-file path even for a process that may
	 * read every file.
	 *
	 * @return void
	 */
	public function testAnUnreadableFragmentIsSkipped(): void {
		$dir = sys_get_temp_dir() . '/decidiq-fragment-merger-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/10-a.json', '{"a":1}');
		symlink($dir . '/missing-target', $dir . '/20-gone.json');

		$warnings = [];
		set_error_handler(
			static function (int $errno, string $message) use (&$warnings): bool {
				$warnings[] = $message;
				return true;
			},
			E_WARNING
		);
		try {
			$result = (new RegisterFragmentMerger())->mergeDirectory(configData: ['base' => true], fragmentDir: $dir);
		} finally {
			restore_error_handler();
			unlink($dir . '/20-gone.json');
			unlink($dir . '/10-a.json');
			rmdir($dir);
		}

		self::assertSame([['base' => true, 'a' => 1], '10-a.json:' . md5('{"a":1}') . ';'], $result);
		self::assertCount(1, $warnings, 'the failed read is the only warning, and it does not stop the merge');
		self::assertStringContainsString('20-gone.json', $warnings[0]);
	}//end testAnUnreadableFragmentIsSkipped()
}//end class
