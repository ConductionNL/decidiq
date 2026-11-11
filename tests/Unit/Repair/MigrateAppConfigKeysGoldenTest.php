<?php

/**
 * Golden runs of MigrateAppConfigKeys' report: every write, output line and
 * log line, in order, for a migration with each kind of key (copied, present,
 * empty, reserved, unreadable) and for an install with nothing stored. Pinned
 * before run() was split into named steps.
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

final class MigrateAppConfigKeysGoldenTest extends TestCase {

	/**
	 * Everything reported, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * The reports, exactly.
	 *
	 * @return void
	 */
	public function testTheReportsAreUnchanged(): void {
		$this->runStep(['secret' => 's', 'present' => 'old', 'empty' => '', 'enabled' => 'yes', 'boom' => 'x', 'plain' => 'p'], ['present' => 'new'], ['secret' => true]);
		$this->runStep([], [], []);

		self::assertSame(
			[
				['set', 'secret', 's', true],
				['log', 'Decidiq: could not migrate one app config key; leaving it under the old namespace', ['key' => 'boom', 'exception' => 'read exploded']],
				['set', 'plain', 'p', false],
				['info', 'MigrateAppConfigKeys: 2 key(s) migrated, 1 already present, 1 had no value to migrate, 1 skipped as Nextcloud-reserved.'],
				['info', 'MigrateAppConfigKeys: no stored decidesk configuration on this install; nothing to do.'],
			],
			$this->trace
		);
	}//end testTheReportsAreUnchanged()

	/**
	 * Run the step over the given values.
	 *
	 * @param array<string, string> $old       Old-namespace values.
	 * @param array<string, string> $new       New-namespace values.
	 * @param array<string, bool>   $sensitive Old keys flagged sensitive.
	 *
	 * @return void
	 */
	private function runStep(array $old, array $new, array $sensitive): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturnCallback(static fn (string $app): array => $app === 'decidesk' ? array_keys($old) : []);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($old, $new): string {
				if ($key === 'boom') {
					throw new RuntimeException('read exploded');
				}

				return $app === 'decidesk' ? ($old[$key] ?? $default) : ($new[$key] ?? $default);
			}
		);
		$config->method('isSensitive')->willReturnCallback(static fn (string $app, string $key): bool => ($sensitive[$key] ?? false));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $isSensitive = false): bool {
				$this->trace[] = ['set', $key, $value, $isSensitive];
				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->trace[] = ['log', $message, $context];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = ['info', $message];
			}
		);

		(new MigrateAppConfigKeys($config, $logger))->run($output);
	}//end runStep()
}//end class
