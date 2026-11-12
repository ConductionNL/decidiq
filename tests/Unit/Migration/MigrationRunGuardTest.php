<?php

/**
 * Characterisation test for the run() entry point of the schema migrations.
 *
 * Twelve repair-step migrations open the same way: skip with an info line when
 * OpenRegister is unavailable, warn when its ObjectService cannot be resolved,
 * and otherwise hand the migration to ObjectService::runAsSystem(). This pins
 * all three outcomes for each of them.
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

use Closure;
use OCA\Decidiq\Migration\AgendaItemTypeResolver;
use OCA\Decidiq\Migration\MigrateCommitments;
use OCA\Decidiq\Migration\MigrateConfidentialityRecords;
use OCA\Decidiq\Migration\MigrateConsultationsToOneSchema;
use OCA\Decidiq\Migration\MigrateDocumentsToAgendaItems;
use OCA\Decidiq\Migration\MigrateKascommissieToAuditStatement;
use OCA\Decidiq\Migration\MigrateMemberOnboarding;
use OCA\Decidiq\Migration\MigratePlanningCycles;
use OCA\Decidiq\Migration\MigrateQuestionsToAgendaItems;
use OCA\Decidiq\Migration\MigrateRegulationsToGoverningDocuments;
use OCA\Decidiq\Migration\MigrateTermRulesToPositionTypes;
use OCA\Decidiq\Migration\MigrateTheLastTwoDutchNames;
use OCA\Decidiq\Migration\MigrateVveToBodyConfiguration;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the three outcomes of run() for every migration that shares it.
 */
class MigrationRunGuardTest extends TestCase {

	/**
	 * The settings mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The output mock.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput $output;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings  = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->output    = $this->createMock(originalClassName: IOutput::class);

	}//end setUp()

	/**
	 * The migrations whose run() is pinned.
	 *
	 * @return array<string, array{0: class-string<IRepairStep>}>
	 */
	public static function migrations(): array {
		return [
			'commitments' => [MigrateCommitments::class],
			'confidentiality records' => [MigrateConfidentialityRecords::class],
			'consultations' => [MigrateConsultationsToOneSchema::class],
			'documents' => [MigrateDocumentsToAgendaItems::class],
			'kascommissie' => [MigrateKascommissieToAuditStatement::class],
			'member onboarding' => [MigrateMemberOnboarding::class],
			'planning cycles' => [MigratePlanningCycles::class],
			'questions' => [MigrateQuestionsToAgendaItems::class],
			'regulations' => [MigrateRegulationsToGoverningDocuments::class],
			'term rules' => [MigrateTermRulesToPositionTypes::class],
			'last two dutch names' => [MigrateTheLastTwoDutchNames::class],
			'vve' => [MigrateVveToBodyConfiguration::class],
		];

	}//end migrations()

	/**
	 * Without OpenRegister the step says so and touches nothing.
	 *
	 * @param class-string<IRepairStep> $class The migration.
	 *
	 * @return void
	 */
	#[DataProvider('migrations')]
	public function testSkipsWithoutOpenRegister(string $class): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects($this->never())->method('get');
		$this->output->expects($this->once())->method('info')->with('OpenRegister unavailable — nothing to migrate.');
		$this->output->expects($this->never())->method('warning');

		$this->migration(class: $class)->run($this->output);

	}//end testSkipsWithoutOpenRegister()

	/**
	 * An unresolvable ObjectService is a warning, not a failure.
	 *
	 * @param class-string<IRepairStep> $class The migration.
	 *
	 * @return void
	 */
	#[DataProvider('migrations')]
	public function testWarnsWhenTheObjectServiceCannotBeResolved(string $class): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->container->expects($this->once())->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willThrowException(new RuntimeException('not registered'));
		$this->output->expects($this->never())->method('info');
		$this->output->expects($this->once())->method('warning')
			->with('Could not resolve OpenRegister ObjectService: not registered');

		$this->migration(class: $class)->run($this->output);

	}//end testWarnsWhenTheObjectServiceCannotBeResolved()

	/**
	 * With OpenRegister the migration is handed to runAsSystem().
	 *
	 * @param class-string<IRepairStep> $class The migration.
	 *
	 * @return void
	 */
	#[DataProvider('migrations')]
	public function testRunsTheMigrationAsSystem(string $class): void {
		$objectService = new class {
			/**
			 * The callables handed to runAsSystem().
			 *
			 * @var list<mixed>
			 */
			public array $runs = [];

			/**
			 * Record the callable without running it.
			 *
			 * @param callable $work The work to run as system.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->runs[] = $work;

			}//end runAsSystem()
		};

		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->container->method('get')->willReturn($objectService);
		$this->output->expects($this->never())->method('info');
		$this->output->expects($this->never())->method('warning');

		$this->migration(class: $class)->run($this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->runs);
		self::assertInstanceOf(expected: Closure::class, actual: $objectService->runs[0]);

	}//end testRunsTheMigrationAsSystem()

	/**
	 * Build a migration over the mocks.
	 *
	 * @param class-string<IRepairStep> $class The migration.
	 *
	 * @return IRepairStep The migration.
	 */
	private function migration(string $class): IRepairStep {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		if ($class === MigrateQuestionsToAgendaItems::class || $class === MigrateDocumentsToAgendaItems::class) {
			return new $class(
				settingsService: $this->settings,
				container: $this->container,
				logger: $logger,
				types: $this->createMock(originalClassName: AgendaItemTypeResolver::class)
			);
		}

		return new $class(settingsService: $this->settings, container: $this->container, logger: $logger);

	}//end migration()
}//end class
