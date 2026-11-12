<?php

/**
 * Behaviour tests for RegisterFragmentMerger.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Settings;

use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Merge semantics of register fragments: lists append, maps merge
 * recursively, scalars and shape changes take the overlay, files apply in
 * name order, and a malformed fragment is reported and skipped.
 *
 * @covers \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 */
class RegisterFragmentMergerBehaviourTest extends TestCase {
	/**
	 * Temporary fragment directory for the current test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create an empty fragment directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/decidiq-fragment-behaviour-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}//end setUp()

	/**
	 * Remove the fragment directory and its files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->dir);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Write one fragment file.
	 *
	 * @param string $name    File name
	 * @param string $content File content
	 *
	 * @return void
	 */
	private function fragment(string $name, string $content): void {
		file_put_contents($this->dir . '/' . $name, $content);
	}//end fragment()

	/**
	 * Scalars in the overlay replace the base; new keys are added; untouched keys stay.
	 *
	 * @return void
	 */
	public function testDeepMergeOverwritesScalarsAndAddsKeys(): void {
		$this->assertSame(
			['a' => 2, 'b' => 'keep', 'c' => null],
			RegisterFragmentMerger::deepMerge(base: ['a' => 1, 'b' => 'keep'], overlay: ['a' => 2, 'c' => null])
		);
	}//end testDeepMergeOverwritesScalarsAndAddsKeys()

	/**
	 * Two lists are concatenated, base first, without de-duplication.
	 *
	 * @return void
	 */
	public function testDeepMergeAppendsLists(): void {
		$this->assertSame(
			['tags' => ['x', 'y', 'y', 'z']],
			RegisterFragmentMerger::deepMerge(base: ['tags' => ['x', 'y']], overlay: ['tags' => ['y', 'z']])
		);
	}//end testDeepMergeAppendsLists()

	/**
	 * Nested maps merge recursively instead of being replaced.
	 *
	 * @return void
	 */
	public function testDeepMergeMergesNestedMaps(): void {
		$base    = ['components' => ['schemas' => ['meeting' => ['title' => 'Meeting', 'version' => '1']]]];
		$overlay = ['components' => ['schemas' => ['meeting' => ['version' => '2'], 'vote' => ['title' => 'Vote']]]];

		$this->assertSame(
			['components' => ['schemas' => [
				'meeting' => ['title' => 'Meeting', 'version' => '2'],
				'vote'    => ['title' => 'Vote'],
			]]],
			RegisterFragmentMerger::deepMerge(base: $base, overlay: $overlay)
		);
	}//end testDeepMergeMergesNestedMaps()

	/**
	 * When only one side is an array, the overlay value wins outright.
	 *
	 * @return void
	 */
	public function testDeepMergeShapeChangeTakesTheOverlay(): void {
		$this->assertSame(
			['a' => ['x'], 'b' => 'flat'],
			RegisterFragmentMerger::deepMerge(base: ['a' => 'scalar', 'b' => ['nested' => 1]], overlay: ['a' => ['x'], 'b' => 'flat'])
		);
	}//end testDeepMergeShapeChangeTakesTheOverlay()

	/**
	 * A list merged with a map is merged by key, the overlay's keys winning.
	 *
	 * @return void
	 */
	public function testDeepMergeListWithMapMergesByKey(): void {
		$this->assertSame(
			['a' => [0 => 'zero', 1 => 'one', 'k' => 'v']],
			RegisterFragmentMerger::deepMerge(base: ['a' => ['zero', 'old']], overlay: ['a' => [1 => 'one', 'k' => 'v']])
		);
	}//end testDeepMergeListWithMapMergesByKey()

	/**
	 * An empty base list is treated as a list, so an overlay list appends to it.
	 *
	 * @return void
	 */
	public function testDeepMergeEmptyBaseCountsAsList(): void {
		$this->assertSame(
			['a' => ['x']],
			RegisterFragmentMerger::deepMerge(base: ['a' => []], overlay: ['a' => ['x']])
		);
	}//end testDeepMergeEmptyBaseCountsAsList()

	/**
	 * A missing fragment directory leaves the config as is with an empty signature.
	 *
	 * @return void
	 */
	public function testMissingDirectoryIsANoOp(): void {
		$merger = new RegisterFragmentMerger();

		$this->assertSame(
			[['a' => 1], ''],
			$merger->mergeDirectory(configData: ['a' => 1], fragmentDir: $this->dir . '/absent')
		);
	}//end testMissingDirectoryIsANoOp()

	/**
	 * Fragments apply in file-name order, so the later file wins, and only .json files count.
	 *
	 * @return void
	 */
	public function testFragmentsApplyInNameOrderAndOnlyJson(): void {
		$this->fragment('20-b.json', '{"v":"b","list":[2]}');
		$this->fragment('10-a.json', '{"v":"a","list":[1]}');
		$this->fragment('30-c.txt', '{"v":"ignored"}');

		[$config, $signature] = (new RegisterFragmentMerger())->mergeDirectory(
			configData: ['list' => [0]],
			fragmentDir: $this->dir
		);

		$this->assertSame(['list' => [0, 1, 2], 'v' => 'b'], $config);
		$this->assertSame(
			'10-a.json:' . md5('{"v":"a","list":[1]}') . ';20-b.json:' . md5('{"v":"b","list":[2]}') . ';',
			$signature
		);
	}//end testFragmentsApplyInNameOrderAndOnlyJson()

	/**
	 * A malformed fragment is reported by name and error, skipped, and left out of the signature.
	 *
	 * @return void
	 */
	public function testMalformedFragmentIsReportedAndSkipped(): void {
		$this->fragment('10-bad.json', '{"v": ');
		$this->fragment('20-good.json', '{"v":"good"}');
		$reported = [];
		$merger   = new RegisterFragmentMerger(
			onMalformed: static function (string $file, string $error) use (&$reported): void {
				$reported[] = [$file, $error];
			}
		);

		[$config, $signature] = $merger->mergeDirectory(configData: ['v' => 'base'], fragmentDir: $this->dir);

		$this->assertSame(['v' => 'good'], $config);
		$this->assertSame('20-good.json:' . md5('{"v":"good"}') . ';', $signature);
		$this->assertCount(1, $reported);
		$this->assertSame('10-bad.json', $reported[0][0]);
		$this->assertSame('Syntax error', $reported[0][1]);
	}//end testMalformedFragmentIsReportedAndSkipped()

	/**
	 * Without a callback a malformed fragment is skipped silently.
	 *
	 * @return void
	 */
	public function testMalformedFragmentWithoutCallbackIsSkippedSilently(): void {
		$this->fragment('10-bad.json', 'not json');

		$this->assertSame(
			[['v' => 'base'], ''],
			(new RegisterFragmentMerger())->mergeDirectory(configData: ['v' => 'base'], fragmentDir: $this->dir)
		);
	}//end testMalformedFragmentWithoutCallbackIsSkippedSilently()
}//end class
