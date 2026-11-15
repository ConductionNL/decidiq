<?php

/**
 * Golden runs of MigrateUserPreferences' report: what the step tells the
 * repair output and the log for a migration with copies and keys already
 * present, for an install with nothing to migrate, for an unreadable user
 * walk and for one unreadable preference. Pinned before run() was split into
 * named steps.
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

use OCA\Decidiq\Repair\MigrateUserPreferences;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MigrateUserPreferencesGoldenTest extends TestCase {

	/**
	 * Everything reported, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * The four reports, exactly.
	 *
	 * @return void
	 */
	public function testTheReportsAreUnchanged(): void {
		$this->runStep(['alice' => ['a' => '1', 'b' => '2', 'c' => ''], 'bob' => ['a' => '3']], ['alice' => ['b' => 'set']], []);
		$this->runStep([], [], []);
		$this->runStep(['alice' => ['a' => '1']], [], ['walk']);
		$this->runStep(['alice' => ['a' => '1', 'boom' => 'x']], [], ['boom']);

		self::assertSame(
			[
				['set', 'alice', 'a', '1'],
				['set', 'bob', 'a', '3'],
				['info', 'MigrateUserPreferences: migrated 2 preference(s); 1 already set under decidiq.'],
				['info', 'MigrateUserPreferences: no stored decidesk user preferences on this install; nothing to do.'],
				['log', 'Decidiq: could not enumerate users; skipping the per-user preference migration', ['exception' => 'walk exploded']],
				['warning', 'MigrateUserPreferences: could not enumerate users; decidesk preferences were left in place.'],
				['set', 'alice', 'a', '1'],
				['log', 'Decidiq: could not migrate one per-user preference; leaving it under the old app id', ['key' => 'boom', 'exception' => 'read exploded']],
				['info', 'MigrateUserPreferences: migrated 1 preference(s); 0 already set under decidiq.'],
			],
			$this->trace
		);
	}//end testTheReportsAreUnchanged()

	/**
	 * Run the step over the given preferences.
	 *
	 * @param array<string, array<string, string>> $old   Old-namespace values per user.
	 * @param array<string, array<string, string>> $new   New-namespace values per user.
	 * @param list<string>                         $fails 'walk' to fail the walk, or keys whose read fails.
	 *
	 * @return void
	 */
	private function runStep(array $old, array $new, array $fails): void {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getKeys')->willReturnCallback(static fn (string $uid, string $app): array => $app === 'decidesk' ? array_keys($old[$uid] ?? []) : []);
		$config->method('getValueString')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $default = '') use ($old, $new, $fails): string {
				if (in_array($key, $fails, true) === true) {
					throw new RuntimeException('read exploded');
				}

				return $app === 'decidesk' ? ($old[$uid][$key] ?? $default) : ($new[$uid][$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): bool {
				$this->trace[] = ['set', $uid, $key, $value];
				return true;
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('callForSeenUsers')->willReturnCallback(
			function (callable $callback) use ($old, $fails): void {
				if (in_array('walk', $fails, true) === true) {
					throw new RuntimeException('walk exploded');
				}

				foreach (array_keys($old) as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn((string)$uid);
					$callback($user);
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->trace[] = ['log', $message, $context];
			}
		);
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->trace[] = [$level, $message];
				}
			);
		}

		(new MigrateUserPreferences($config, $users, $logger))->run($output);
	}//end runStep()
}//end class
