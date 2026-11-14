<?php

/**
 * Unit tests for RepairDecisionStageLabels.
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

use OCA\Decidiq\Migration\RepairDecisionStageLabels;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The repair step that backfills decision-stage labels.
 *
 * @covers \OCA\Decidiq\Migration\RepairDecisionStageLabels
 */
final class RepairDecisionStageLabelsTest extends TestCase {

	/**
	 * An object service that records whether work ran inside its system scope.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return new class {
			public bool $inSystemScope = false;

			public function runAsSystem(callable $callback): mixed {
				$this->inSystemScope = true;
				try {
					return $callback();
				} finally {
					$this->inSystemScope = false;
				}
			}
		};

	}//end objectService()

	/**
	 * Build the step over the given container entries.
	 *
	 * @param bool                  $available Whether OpenRegister is available
	 * @param array<string, object> $services  Container entries by id
	 * @param LoggerInterface|null  $logger    The logger
	 *
	 * @return RepairDecisionStageLabels
	 */
	private function step(bool $available, array $services, ?LoggerInterface $logger = null): RepairDecisionStageLabels {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException("no $id");
				}

				return $services[$id];
			}
		);

		return new RepairDecisionStageLabels(
			settingsService: $settings,
			container: $container,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);

	}//end step()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertSame('Backfill labels onto Decidiq decision stages stored without one', $this->step(true, [])->getName());

	}//end testTheStepNamesWhatItDoes()

	/**
	 * Without OpenRegister there is nothing to repair.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsRepaired(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('OpenRegister unavailable — no stages to repair.');
		$output->expects(self::never())->method('warning');

		$this->step(false, [])->run($output);

	}//end testWithoutOpenRegisterNothingIsRepaired()

	/**
	 * A repair that cannot be resolved is a warning, not a failure.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRepairWarns(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');
		$output->expects(self::once())->method('warning')
			->with('Could not resolve the stage-label repair: no OCA\Decidiq\Service\DecisionStageLabelRepair');

		$this->step(true, ['OCA\OpenRegister\Service\ObjectService' => $this->objectService()])->run($output);

	}//end testAnUnresolvableRepairWarns()

	/**
	 * The repair runs in the system scope and its count is reported.
	 *
	 * @return void
	 */
	public function testTheRepairRunsAsSystemAndIsCounted(): void {
		$objectService = $this->objectService();
		$repair = new class ($objectService) {
			public ?bool $ranAsSystem = null;

			public function __construct(private readonly object $objectService) {
			}

			public function repair(): int {
				$this->ranAsSystem = $this->objectService->inSystemScope;
				return 3;
			}
		};
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Backfilled labels onto 3 decision stage(s).');
		$output->expects(self::never())->method('warning');

		$this->step(
			true,
			[
				'OCA\OpenRegister\Service\ObjectService' => $objectService,
				'OCA\Decidiq\Service\DecisionStageLabelRepair' => $repair,
			]
		)->run($output);

		self::assertTrue($repair->ranAsSystem);

	}//end testTheRepairRunsAsSystemAndIsCounted()

	/**
	 * A repair that throws is logged and warned about.
	 *
	 * @return void
	 */
	public function testAFailingRepairIsLoggedAndWarned(): void {
		$repair = new class {
			public function repair(): int {
				throw new RuntimeException('schema missing');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: decision-stage label backfill skipped',
			['exception' => 'schema missing']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');
		$output->expects(self::once())->method('warning')->with('Decision-stage label backfill skipped: schema missing');

		$this->step(
			true,
			[
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService(),
				'OCA\Decidiq\Service\DecisionStageLabelRepair' => $repair,
			],
			$logger
		)->run($output);

	}//end testAFailingRepairIsLoggedAndWarned()
}//end class
