<?php

/**
 * Unit tests for MigrateTheLastTwoDutchNames.
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

use OCA\Decidiq\Migration\MigrateTheLastTwoDutchNames;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The rename of the forward-agenda and delegation records into plain words.
 *
 * @spec openspec/specs/the-last-two-dutch-names/spec.md
 */
class MigrateTheLastTwoDutchNamesTest extends TestCase {

	private const BODY_A = '11111111-1111-4111-8111-111111111111';

	private const BODY_B = '22222222-2222-4222-8222-222222222222';

	private const DECISION = '33333333-3333-4333-8333-333333333333';

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateTheLastTwoDutchNames $migration;

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

		$this->migration = new MigrateTheLastTwoDutchNames(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * A legacy delegation row.
	 *
	 * @param string              $id    The source identifier.
	 * @param array<string,mixed> $extra Extra or overriding properties.
	 *
	 * @return array<string,mixed> The row.
	 */
	private static function delegation(string $id, array $extra = []): array {
		return ($extra + [
			'id' => $id,
			'type' => 'mandate',
			'subject' => 'Subsidies tot 50.000 euro',
			'decision' => self::DECISION,
			'validFrom' => '2026-01-01',
			'status' => 'effective',
		]);

	}//end delegation()

	/**
	 * The four Dutch properties land on their plain-word names, and the payload
	 * fits the shipped AuthorityDelegation schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/the-last-two-dutch-names/spec.md#requirement-the-dutch-properties-are-renamed-with-their-schema
	 */
	public function testTheDutchPropertiesLandOnTheirPlainNames(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			delegations: [
				self::delegation(
					id: 'bt-1',
					extra: [
						'delegans' => self::BODY_A,
						'delegansDescription' => 'De gemeenteraad',
						'delegatarisBody' => self::BODY_B,
						'beperkingen' => 'Niet voor bouwvergunningen',
					]
				),
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$written = $service->writtenFor('authority-delegation');
		self::assertCount(expectedCount: 1, haystack: $written);
		$row = $written[0];
		self::assertSame(expected: self::BODY_A, actual: $row['delegatingBody']);
		self::assertSame(expected: 'De gemeenteraad', actual: $row['delegatingDescription']);
		self::assertSame(expected: self::BODY_B, actual: $row['delegateBody']);
		self::assertSame(expected: 'Niet voor bouwvergunningen', actual: $row['restrictions']);
		foreach (['delegans', 'delegansDescription', 'delegatarisBody', 'beperkingen'] as $dutch) {
			self::assertArrayNotHasKey(key: $dutch, array: $row);
		}

		self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'authority-delegation', payload: $row));

	}//end testTheDutchPropertiesLandOnTheirPlainNames()

	/**
	 * A sub-delegation points at its copied parent, also when it is read first.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/the-last-two-dutch-names/spec.md#requirement-existing-records-are-carried-across
	 */
	public function testASubDelegationPointsAtItsCopiedParent(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$parentId = '44444444-4444-4444-8444-444444444444';
		$service  = $this->makeObjectService(
			delegations: [
				// The child comes first: the source list carries no order.
				self::delegation(id: 'child-1', extra: ['parentAllocation' => $parentId, 'type' => 'mandate']),
				self::delegation(id: $parentId),
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$saved  = $service->savedFor('authority-delegation');
		$byFrom = array_column($saved, null, 'migratedFromObject');
		self::assertCount(expectedCount: 2, haystack: $saved);
		self::assertSame(expected: $byFrom[$parentId]['id'], actual: $byFrom['child-1']['parentAllocation']);

	}//end testASubDelegationPointsAtItsCopiedParent()

	/**
	 * A forward-agenda item keeps its values, including a retired enum value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/the-last-two-dutch-names/spec.md#requirement-the-forward-agenda-vocabularies-are-configuration
	 */
	public function testAPlannedItemKeepsItsValues(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			items: [
				[
					'id' => 'ta-1',
					'subject' => 'Kadernota 2027',
					'governanceBody' => self::BODY_A,
					'plannedPeriod' => '2026-Q4',
					'expectedType' => 'raadsvoorstel',
					'ownerType' => 'college',
					'lifecycle' => 'planned',
					'notes' => '',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$row = $service->writtenFor('planned-agenda-item')[0];
		self::assertSame(expected: 'Kadernota 2027', actual: $row['subject']);
		self::assertSame(expected: self::BODY_A, actual: $row['governanceBody']);
		self::assertSame(expected: 'raadsvoorstel', actual: $row['expectedType']);
		self::assertSame(expected: 'college', actual: $row['ownerType']);
		self::assertSame(expected: 'ta-1', actual: $row['migratedFromObject']);
		self::assertArrayNotHasKey(key: 'notes', array: $row);
		self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'planned-agenda-item', payload: $row));

	}//end testAPlannedItemKeepsItsValues()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/the-last-two-dutch-names/spec.md#requirement-existing-records-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			delegations: [self::delegation(id: 'bt-1')],
			existing: ['authority-delegation' => [['id' => 'ad-1', 'migratedFromObject' => 'bt-1']]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->writtenFor('authority-delegation'));

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
	 * @param array<int,array<string,mixed>>               $items        Legacy forward-agenda items.
	 * @param array<int,array<string,mixed>>               $delegations  Legacy delegations.
	 * @param array<string,array<int,array<string,mixed>>> $existing     Rows already copied, by schema.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $items = [],
		array $delegations = [],
		array $existing = [],
	): object {
		return new class($items, $delegations, $existing) {
			/**
			 * The schema currently selected.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Saves, as [schema, payload, id] triples.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>,2:string}>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>>               $items        Legacy forward-agenda items.
			 * @param array<int,array<string,mixed>>               $delegations  Legacy delegations.
			 * @param array<string,array<int,array<string,mixed>>> $existing     Rows already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $items,
				private array $delegations,
				private array $existing,
			) {
			}//end __construct()

			/**
			 * Payloads saved for one schema, each carrying the id it was given.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function savedFor(string $schema): array {
				$out = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $schema) {
						$out[] = ($save[1] + ['id' => $save[2]]);
					}
				}

				return $out;

			}//end savedFor()

			/**
			 * Payloads exactly as written for one schema, without the assigned id.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function writtenFor(string $schema): array {
				$out = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $schema) {
						$out[] = $save[1];
					}
				}

				return $out;

			}//end writtenFor()

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

				return match ($this->currentSchema) {
					'termijnagenda-item' => $this->items,
					'bevoegdheidstoedeling' => $this->delegations,
					default => array_merge(
						($this->existing[$this->currentSchema] ?? []),
						$this->savedFor($this->currentSchema)
					),
				};

			}//end findAll()

			/**
			 * Record a save, and hand back an object carrying a NEW id.
			 *
			 * The id deliberately does not look like the source id, so a test can
			 * tell a retargeted reference from one that was copied verbatim.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				$id = 'new-' . $schema . '-' . (count($this->saves) + 1);
				$this->saves[] = [$schema, $object, $id];

				return ($object + ['id' => $id]);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
