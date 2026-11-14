<?php

/**
 * Unit tests for MigrateParticipationsToCompositions.
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

use OCA\Decidiq\Migration\MigrateParticipationsToCompositions;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The move of shared-body participations onto representation compositions.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
 */
class MigrateParticipationsToCompositionsTest extends TestCase {

	/**
	 * Reports OpenRegister availability.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settingsService;

	/**
	 * Hands out the fake ObjectService.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The repair output.
	 *
	 * @var IOutput
	 */
	private IOutput $output;

	/**
	 * The migration under test.
	 *
	 * @var MigrateParticipationsToCompositions
	 */
	private MigrateParticipationsToCompositions $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container       = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output          = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateParticipationsToCompositions(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * A participation becomes a representation composition of the shared body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	public function testAParticipationBecomesARepresentationComposition(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			participations: [
				[
					'id' => 'bp-1',
					'sharedBody' => 'regio-noordoost',
					'participant' => 'gemeente-oss',
					'seats' => 2,
					'votingWeight' => '3',
					'accessionDate' => '2015-01-01T00:00:00Z',
					'exitDate' => '2026-06-30T22:00:00+00:00',
					'label' => '  Deelnemer per oprichting  ',
				],
			],
		);
		$this->container->method('get')->willReturn($service);
		$this->output->expects(self::once())->method('info')
			->with(self::stringContains(string: '1 composition(s), 0 without both bodies'));

		$this->migration->run(output: $this->output);

		self::assertSame(
			expected: [
				[
					'composite' => 'uuid-of-regio-noordoost',
					'component' => 'uuid-of-gemeente-oss',
					'compositionType' => 'representation',
					'migratedFromObject' => 'bp-1',
					'seats' => 2,
					'votingWeight' => 3.0,
					// The day the stored date-time named; the composition stores dates.
					'accessionDate' => '2015-01-01',
					'exitDate' => '2026-06-30',
					'label' => 'Deelnemer per oprichting',
				],
			],
			actual: $service->savedFor('governance-body-composition'),
		);

	}//end testAParticipationBecomesARepresentationComposition()

	/**
	 * What the participation left empty or unreadable is left out, not invented.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	public function testEmptyAndUnreadableFieldsAreLeftOut(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			participations: [
				[
					'id' => 'bp-1',
					'sharedBody' => 'regio',
					'participant' => 'oss',
					'seats' => 'two',
					'accessionDate' => '2026-02-30',
					'exitDate' => null,
					'label' => '   ',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertSame(
			expected: [
				'composite' => 'uuid-of-regio',
				'component' => 'uuid-of-oss',
				'compositionType' => 'representation',
				'migratedFromObject' => 'bp-1',
			],
			actual: $service->savedFor('governance-body-composition')[0],
		);

	}//end testEmptyAndUnreadableFieldsAreLeftOut()

	/**
	 * A participation missing either body is counted and skipped, not bound to nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	public function testAParticipationWithoutBothBodiesIsSkipped(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			participations: [
				['id' => 'bp-1', 'sharedBody' => 'regio'],
				['id' => 'bp-2', 'participant' => 'oss'],
			],
		);
		$this->container->method('get')->willReturn($service);
		$this->output->expects(self::once())->method('info')
			->with(self::stringContains(string: '0 composition(s), 2 without both bodies'));

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governance-body-composition'));

	}//end testAParticipationWithoutBothBodiesIsSkipped()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			participations: [['id' => 'bp-1', 'sharedBody' => 'regio', 'participant' => 'oss']],
			existing: [['id' => 'gbc-1', 'migratedFromObject' => 'bp-1']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governance-body-composition'));

	}//end testASecondRunCopiesNothing()

	/**
	 * Nothing runs when OpenRegister is unavailable.
	 *
	 * @return void
	 *
	 * @spec exclude Guard clause; asserts the migration is inert without OpenRegister.
	 */
	public function testNothingRunsWithoutOpenRegister(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		$this->migration->run(output: $this->output);

	}//end testNothingRunsWithoutOpenRegister()

	/**
	 * A fake ObjectService recording what the migration writes.
	 *
	 * @param array<int,array<string,mixed>> $participations Legacy participations.
	 * @param array<int,array<string,mixed>> $existing       Compositions already copied.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $participations = [],
		array $existing = [],
	): object {
		return new class($participations, $existing) {
			/**
			 * The schema currently selected.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Saves, as [schema, payload] pairs.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>}>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $participations Legacy participations.
			 * @param array<int,array<string,mixed>> $existing       Compositions already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $participations,
				private array $existing,
			) {
			}//end __construct()

			/**
			 * Payloads saved for one schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function savedFor(string $schema): array {
				$out = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $schema) {
						$out[] = $save[1];
					}
				}

				return $out;

			}//end savedFor()

			/**
			 * Run an operation as the system user.
			 *
			 * @param callable $operation The operation to run.
			 *
			 * @return mixed The operation's result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();

			}//end runAsSystem()

			/**
			 * Select the register.
			 *
			 * @param string $register The register slug.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}//end setRegister()

			/**
			 * Select the schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->currentSchema = $schema;

			}//end setSchema()

			/**
			 * Return the rows for the selected schema.
			 *
			 * @param array<string,mixed> $filters Slug lookups.
			 *
			 * @return array<int,array<string,mixed>> The rows.
			 */
			public function findAll(array $filters = []): array {
				$slug = (string)($filters['filters']['@self']['slug'] ?? '');
				if ($slug !== '') {
					return [['id' => 'uuid-of-' . $slug]];
				}

				$saved = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $this->currentSchema) {
						$saved[] = $save[1];
					}
				}

				return match ($this->currentSchema) {
					'body-participation' => $this->participations,
					'governance-body-composition' => array_merge($this->existing, $saved),
					default => [],
				};

			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				$this->saves[] = [$schema, $object];

				return ($object + ['id' => $schema . '-' . count($this->saves)]);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
