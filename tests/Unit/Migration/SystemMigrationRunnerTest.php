<?php

/**
 * Unit tests for SystemMigrationRunner.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\SystemMigrationRunner;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Running a migration body inside OpenRegister's system scope.
 *
 * @covers \OCA\Decidiq\Migration\SystemMigrationRunner
 */
final class SystemMigrationRunnerTest extends TestCase {

	/**
	 * Without OpenRegister nothing is resolved and the body never runs.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheBodyNeverRuns(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('OpenRegister unavailable — nothing to migrate.');

		$ran = false;
		(new SystemMigrationRunner(settingsService: $settings, container: $container))->run(
			output: $output,
			migrateAll: static function () use (&$ran): void {
				$ran = true;
			}
		);

		self::assertFalse($ran);

	}//end testWithoutOpenRegisterTheBodyNeverRuns()

	/**
	 * An unresolvable ObjectService is reported as a warning and the body never runs.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceWarnsAndSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('Could not resolve OpenRegister ObjectService: not registered');

		$ran = false;
		(new SystemMigrationRunner(settingsService: $settings, container: $container))->run(
			output: $output,
			migrateAll: static function () use (&$ran): void {
				$ran = true;
			}
		);

		self::assertFalse($ran);

	}//end testAnUnresolvableObjectServiceWarnsAndSkips()

	/**
	 * The body runs inside runAsSystem() with the resolved service and the output.
	 *
	 * @return void
	 */
	public function testTheBodyRunsAsSystemWithTheServiceAndOutput(): void {
		$objectService = new class {
			public bool $inSystemScope = false;

			public int $scopes = 0;

			public function runAsSystem(callable $callback): mixed {
				$this->scopes++;
				$this->inSystemScope = true;
				try {
					return $callback();
				} finally {
					$this->inSystemScope = false;
				}
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);
		$output = $this->createMock(IOutput::class);

		$seen = [];
		(new SystemMigrationRunner(settingsService: $settings, container: $container))->run(
			output: $output,
			migrateAll: static function (object $objectService, IOutput $output) use (&$seen): void {
				$seen = [$objectService, $output, $objectService->inSystemScope];
			}
		);

		self::assertSame($objectService, $seen[0]);
		self::assertSame($output, $seen[1]);
		self::assertTrue($seen[2]);
		self::assertSame(1, $objectService->scopes);

	}//end testTheBodyRunsAsSystemWithTheServiceAndOutput()
}//end class
