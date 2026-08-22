<?php

/**
 * Unit tests for the MigrateAppConfigKeys repair step.
 *
 * These tests exist because the failure this step prevents is SILENT: after the
 * `decidesk` -> `decidiq` app-id rename every stored `IAppConfig` value becomes
 * unreachable, every reader falls back to its default, and nothing throws.
 *
 * The doubles here can fail on READ as well as on write. An earlier app in this
 * rename programme shipped a step whose reads sat outside the `try`, and its
 * test fake could only refuse writes — so the fixture could not express the
 * production failure and the defect shipped green.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateAppConfigKeys.
 */
class MigrateAppConfigKeysTest extends TestCase {

	/**
	 * The app id this app used before the rename.
	 *
	 * @var string
	 */
	private const OLD = 'decidesk';

	/**
	 * The app id this app uses after the rename.
	 *
	 * @var string
	 */
	private const NEW = 'decidiq';

	/**
	 * Build the step over an in-memory two-namespace app-config double.
	 *
	 * @param array<string, string> $old        Values stored under the old app id.
	 * @param array<string, string> $new        Values already stored under the new app id.
	 * @param array<string, string> &$written   Receives every write the step performs.
	 * @param array<string, bool>   &$sensitive Receives the sensitive flag of every write.
	 * @param string[]              $failReads  Keys whose READ throws.
	 * @param bool                  $failKeys   Whether getKeys() itself throws.
	 * @param array<string, bool>   $sensitiveSource Sensitivity of the old values.
	 *
	 * @return MigrateAppConfigKeys
	 */
	private function step(
		array $old,
		array $new,
		array &$written,
		array &$sensitive,
		array $failReads = [],
		bool $failKeys = false,
		array $sensitiveSource = [],
	): MigrateAppConfigKeys {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);

		$appConfig->method('getKeys')->willReturnCallback(
			static function (string $app) use ($old, $failKeys): array {
				if ($failKeys === true) {
					throw new RuntimeException('getKeys exploded');
				}

				if ($app === self::OLD) {
					return array_keys($old);
				}

				return [];
			}
		);

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($old, $new, $failReads): string {
				if (in_array($key, $failReads, true) === true) {
					throw new RuntimeException('read exploded for '.$key);
				}

				if ($app === self::OLD) {
					return ($old[$key] ?? $default);
				}

				return ($new[$key] ?? $default);
			}
		);

		$appConfig->method('isSensitive')->willReturnCallback(
			static function (string $app, string $key) use ($sensitiveSource): bool {
				return ($sensitiveSource[$key] ?? false);
			}
		);

		$appConfig->method('setValueString')->willReturnCallback(
			static function (
				string $app,
				string $key,
				string $value,
				bool $lazy = false,
				bool $isSensitive = false,
			) use (&$written, &$sensitive): bool {
				$written[$app.'/'.$key] = $value;
				$sensitive[$app.'/'.$key] = $isSensitive;
				return true;
			}
		);

		return new MigrateAppConfigKeys(
			$appConfig,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end step()

	/**
	 * Every non-reserved key with a value is copied into the new namespace.
	 *
	 * @return void
	 */
	public function testCopiesStoredValuesToTheNewNamespace(): void {
		$written = [];
		$sensitive = [];
		$step = $this->step(
			old: ['voter_token_secret' => 'abc123', 'retention_days' => '30'],
			new: [],
			written: $written,
			sensitive: $sensitive,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame('abc123', $written[self::NEW.'/voter_token_secret'] ?? null);
		self::assertSame('30', $written[self::NEW.'/retention_days'] ?? null);

	}//end testCopiesStoredValuesToTheNewNamespace()

	/**
	 * Nextcloud-reserved keys are never copied.
	 *
	 * Copying `enabled` with setValueString() stores type STRING over
	 * AppManager's MIXED and permanently breaks the next `app:enable` with an
	 * AppConfigTypeConflictException.
	 *
	 * The fixture gives the reserved keys values that are NOT present under the
	 * new app id, so the never-overwrite guard cannot be what suppresses the
	 * write — otherwise this test would still pass with RESERVED_KEYS emptied.
	 *
	 * @return void
	 */
	public function testSkipsNextcloudReservedKeys(): void {
		$written = [];
		$sensitive = [];
		$step = $this->step(
			old: [
				'enabled' => 'yes',
				'installed_version' => '0.9.0',
				'types' => 'dav',
				'retention_days' => '30',
			],
			new: [],
			written: $written,
			sensitive: $sensitive,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertArrayNotHasKey(self::NEW.'/enabled', $written);
		self::assertArrayNotHasKey(self::NEW.'/installed_version', $written);
		self::assertArrayNotHasKey(self::NEW.'/types', $written);
		self::assertSame('30', $written[self::NEW.'/retention_days'] ?? null);

	}//end testSkipsNextcloudReservedKeys()

	/**
	 * A value already present under the new app id is never clobbered.
	 *
	 * @return void
	 */
	public function testNeverOverwritesAValueAlreadySetUnderTheNewAppId(): void {
		$written = [];
		$sensitive = [];
		$step = $this->step(
			old: ['retention_days' => '30'],
			new: ['retention_days' => '90'],
			written: $written,
			sensitive: $sensitive,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertArrayNotHasKey(self::NEW.'/retention_days', $written);

	}//end testNeverOverwritesAValueAlreadySetUnderTheNewAppId()

	/**
	 * A sensitive value keeps its flag when copied.
	 *
	 * Losing the flag would print the vote-signing HMAC key in cleartext in
	 * `occ config:list` and in every support dump.
	 *
	 * @return void
	 */
	public function testCarriesTheSensitiveFlagAcrossTheCopy(): void {
		$written = [];
		$sensitive = [];
		$step = $this->step(
			old: ['voter_token_secret' => 'abc123', 'retention_days' => '30'],
			new: [],
			written: $written,
			sensitive: $sensitive,
			sensitiveSource: ['voter_token_secret' => true],
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertTrue($sensitive[self::NEW.'/voter_token_secret'] ?? false);
		self::assertFalse($sensitive[self::NEW.'/retention_days'] ?? true);

	}//end testCarriesTheSensitiveFlagAcrossTheCopy()

	/**
	 * A READ that throws is logged and the loop continues.
	 *
	 * This is the regression guard for the defect that shipped in two earlier
	 * apps: the reads sat outside the `try`, so one unreadable key propagated
	 * out of run(). Because the step is registered under `<install>`, that does
	 * not merely fail an upgrade — the app never enables and every route goes
	 * with it.
	 *
	 * @return void
	 */
	public function testAReadThatThrowsDoesNotAbortTheRun(): void {
		$written = [];
		$sensitive = [];
		$step = $this->step(
			old: ['broken_key' => 'x', 'retention_days' => '30'],
			new: [],
			written: $written,
			sensitive: $sensitive,
			failReads: ['broken_key'],
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame('30', $written[self::NEW.'/retention_days'] ?? null);
		self::assertArrayNotHasKey(self::NEW.'/broken_key', $written);

	}//end testAReadThatThrowsDoesNotAbortTheRun()

	/**
	 * An unreadable key enumeration is survivable and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableKeyEnumerationIsSurvivable(): void {
		$written = [];
		$sensitive = [];
		$step = $this->step(
			old: ['retention_days' => '30'],
			new: [],
			written: $written,
			sensitive: $sensitive,
			failKeys: true,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame([], $written);

	}//end testAnUnreadableKeyEnumerationIsSurvivable()

}//end class
