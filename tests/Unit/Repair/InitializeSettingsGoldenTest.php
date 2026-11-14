<?php

/**
 * Golden runs of InitializeSettings::run().
 *
 * Records every app-config read and write, output line and log line, in
 * order, for an install without a voter token secret, one with a secret that
 * cannot be re-flagged, and for each outcome of the configuration import
 * (OpenRegister missing, imported, an import issue, an import that throws).
 * The generated secret is checked for its shape and masked. Pinned before
 * run() was split into named steps.
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

use OCA\Decidiq\Repair\InitializeSettings;
use OCA\Decidiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class InitializeSettingsGoldenTest extends TestCase {

	/**
	 * Everything the step did, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Each run's trace matches.
	 *
	 * @return void
	 */
	public function testEveryRunIsUnchanged(): void {
		$runs = [
			'fresh install, imported' => $this->runStep('', ['available' => true, 'result' => ['success' => true, 'version' => '1.2']]),
			'secret present, flag fails, no OpenRegister' => $this->runStep('existing', ['available' => false, 'flagFails' => true]),
			'import issue without a message' => $this->runStep('existing', ['available' => true, 'result' => ['success' => false]]),
			'import issue with a message' => $this->runStep('existing', ['available' => true, 'result' => ['success' => false, 'message' => 'bad']]),
			'import without a version' => $this->runStep('existing', ['available' => true, 'result' => ['success' => true]]),
			'import throws' => $this->runStep('existing', ['available' => true, 'throws' => true]),
		];

		self::assertSame(
			[
				['out.info', 'Initializing Decidiq configuration...'],
				['get', 'decidiq', 'voter_token_secret'],
				['set', 'decidiq', 'voter_token_secret', '<64 hex>', true],
				['out.info', 'Generated voter_token_secret for Decidiq.'],
				['get', 'decidiq', 'voter_token_secret'],
				['flag', 'decidiq', 'voter_token_secret', true],
				['available'],
				['load'],
				['out.info', 'Decidiq configuration imported successfully (version: 1.2)'],
			],
			$runs['fresh install, imported']
		);
		self::assertSame(
			[
				['out.info', 'Initializing Decidiq configuration...'],
				['get', 'decidiq', 'voter_token_secret'],
				['get', 'decidiq', 'voter_token_secret'],
				['flag', 'decidiq', 'voter_token_secret', true],
				['out.warning', 'Could not flag voter_token_secret as sensitive: flag refused'],
				['available'],
				['out.warning', 'OpenRegister is not installed or enabled. Skipping auto-configuration.'],
				['log.warning', 'Decidiq: OpenRegister not available, skipping register initialization', []],
			],
			$runs['secret present, flag fails, no OpenRegister']
		);
		self::assertSame(['out.warning', 'Decidiq configuration import issue: unknown error'], end($runs['import issue without a message']));
		self::assertSame(['out.warning', 'Decidiq configuration import issue: bad'], end($runs['import issue with a message']));
		self::assertSame(['out.info', 'Decidiq configuration imported successfully (version: unknown)'], end($runs['import without a version']));
		self::assertSame(
			[
				['load'],
				['out.warning', 'Could not auto-configure Decidiq: import exploded'],
				['log.error', 'Decidiq initialization failed', ['exception' => 'import exploded']],
			],
			array_slice($runs['import throws'], -3)
		);
	}//end testEveryRunIsUnchanged()

	/**
	 * Run the step once.
	 *
	 * @param string               $secret The stored secret, '' for none.
	 * @param array<string, mixed> $setup  available, result, throws, flagFails.
	 *
	 * @return list<array<int, mixed>> The trace.
	 */
	private function runStep(string $secret, array $setup): array {
		$this->trace = [];
		$stored = $secret;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key) use (&$stored): string {
				$this->trace[] = ['get', $app, $key];
				return $stored;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$stored): bool {
				self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value);
				$stored = $value;
				$this->trace[] = ['set', $app, $key, '<64 hex>', $sensitive];
				return true;
			}
		);
		$config->method('updateSensitive')->willReturnCallback(
			function (string $app, string $key, bool $sensitive) use ($setup): bool {
				$this->trace[] = ['flag', $app, $key, $sensitive];
				if (($setup['flagFails'] ?? false) === true) {
					throw new RuntimeException('flag refused');
				}

				return true;
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturnCallback(
			function () use ($setup): bool {
				$this->trace[] = ['available'];
				return $setup['available'];
			}
		);
		$settings->method('loadConfiguration')->willReturnCallback(
			function () use ($setup): array {
				$this->trace[] = ['load'];
				if (($setup['throws'] ?? false) === true) {
					throw new RuntimeException('import exploded');
				}

				return $setup['result'];
			}
		);
		$output = $this->createMock(IOutput::class);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->trace[] = ['out.' . $level, $message];
				}
			);
		}

		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = ['log.' . $level, $message, $context];
				}
			);
		}

		(new InitializeSettings(settingsService: $settings, logger: $logger, appConfig: $config))->run($output);
		return $this->trace;
	}//end runStep()
}//end class
