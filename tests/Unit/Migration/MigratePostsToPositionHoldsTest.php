<?php

/**
 * Unit tests for MigratePostsToPositionHolds.
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

use OCA\Decidiq\Migration\MigratePostsToPositionHolds;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The move of posts onto position types and of the posts memberships held onto position holds.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class MigratePostsToPositionHoldsTest extends TestCase {

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
	 * The repair output.
	 *
	 * @var IOutput
	 */
	private IOutput $output;

	/**
	 * The migration under test.
	 *
	 * @var MigratePostsToPositionHolds
	 */
	private MigratePostsToPositionHolds $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container       = $this->createMock(originalClassName: ContainerInterface::class);
		$this->output          = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigratePostsToPositionHolds(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

	}//end setUp()

	/**
	 * A post becomes a position type of its body, named by its label and keyed by its role.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAPostBecomesAPositionTypeOfItsBody(): void {
		$service = $this->makeObjectService(rows: [
			'post' => [
				['id' => 'post-1', 'label' => '  Voorzitter  ', 'role' => 'chair', 'governanceBody' => 'gemeenteraad'],
				['id' => 'post-2', 'role' => 'treasurer', 'governanceBody' => 'bestuur'],
				['id' => 'post-3', 'governanceBody' => 'bestuur'],
			],
		]);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertSame(
			expected: [
				['name' => 'Voorzitter', 'governanceBody' => 'uuid-of-gemeenteraad', 'migratedFromObject' => 'post-1', 'key' => 'chair'],
				['name' => 'Treasurer', 'governanceBody' => 'uuid-of-bestuur', 'migratedFromObject' => 'post-2', 'key' => 'treasurer'],
				['name' => 'Post', 'governanceBody' => 'uuid-of-bestuur', 'migratedFromObject' => 'post-3'],
			],
			actual: $service->savedFor('position-type'),
		);

	}//end testAPostBecomesAPositionTypeOfItsBody()

	/**
	 * A membership's post becomes a regular hold on that membership, with the membership's dates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-007-one-person-carries-two-independent-end-terms
	 */
	public function testAMembershipsPostBecomesAHoldWithTheMembershipsDates(): void {
		$service = $this->makeObjectService(rows: [
			'post' => [
				['id' => 'post-1', 'label' => 'Griffier', 'role' => 'secretary', 'governanceBody' => 'raad', 'startDate' => '2010-01-01T00:00:00Z', '@self' => ['slug' => 'post-griffier']],
			],
			'membership' => [
				['id' => 'm-1', 'post' => 'post-griffier', 'startDate' => '2022-03-30T10:00:00+02:00', 'endDate' => '2026-03-31'],
				['id' => 'm-2', 'post' => 'post-1'],
				['id' => 'm-3', 'startDate' => '2022-03-30'],
			],
		]);
		$this->container->method('get')->willReturn($service);
		$this->output->expects(self::once())->method('info')
			->with(self::stringContains(string: '1 position type(s), 0 post(s) without a body, 2 position hold(s), 0 membership post(s) not copied'));

		$this->migration->run(output: $this->output);

		self::assertSame(
			expected: [
				// The slug a seed wrote resolves to the copied position, and the holder's own dates are kept.
				['membership' => 'm-1', 'position' => 'position-type-1', 'holdType' => 'regular', 'startDate' => '2022-03-30', 'migratedFromObject' => 'm-1', 'governanceBody' => 'uuid-of-raad', 'endDate' => '2026-03-31'],
				// Without a start of its own the hold starts when the post did.
				['membership' => 'm-2', 'position' => 'position-type-1', 'holdType' => 'regular', 'startDate' => '2010-01-01', 'migratedFromObject' => 'm-2', 'governanceBody' => 'uuid-of-raad'],
			],
			actual: $service->savedFor('position-hold'),
		);

	}//end testAMembershipsPostBecomesAHoldWithTheMembershipsDates()

	/**
	 * A post without a body, a post that does not exist and a hold without a start are counted, not invented.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testWhatCannotBeCopiedIsCountedAndLeftOut(): void {
		$service = $this->makeObjectService(rows: [
			'post' => [
				['id' => 'post-1', 'label' => 'Zwevend'],
				['id' => 'post-2', 'label' => 'Lid', 'governanceBody' => 'raad'],
			],
			'membership' => [
				['id' => 'm-1', 'post' => 'post-1', 'startDate' => '2024-01-01'],
				['id' => 'm-2', 'post' => 'post-weg', 'startDate' => '2024-01-01'],
				['id' => 'm-3', 'post' => 'post-2', 'startDate' => 'ooit'],
			],
		]);
		$this->container->method('get')->willReturn($service);
		$this->output->expects(self::once())->method('info')
			->with(self::stringContains(string: '1 position type(s), 1 post(s) without a body, 0 position hold(s), 3 membership post(s) not copied'));

		$this->migration->run(output: $this->output);

		self::assertSame(expected: ['Lid'], actual: array_column($service->savedFor('position-type'), 'name'));
		self::assertSame(expected: [], actual: $service->savedFor('position-hold'));

	}//end testWhatCannotBeCopiedIsCountedAndLeftOut()

	/**
	 * An appointment decision's target posts are repointed at their position types.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAnAppointmentsTargetPostsArePointedAtThePositionTypes(): void {
		$service = $this->makeObjectService(rows: [
			'post' => [
				['id' => 'post-1', 'label' => 'Voorzitter', 'governanceBody' => 'raad'],
			],
			'decision' => [
				['id' => 'd-1', 'title' => 'Benoeming', 'targetPosts' => ['post-1', 'pt-already'], '@self' => ['id' => 'd-1']],
				['id' => 'd-2', 'title' => 'Iets anders', 'targetPosts' => ['pt-already']],
				['id' => 'd-3', 'title' => 'Geen doel'],
			],
		]);
		$this->container->method('get')->willReturn($service);
		$this->output->expects(self::once())->method('info')
			->with(self::stringContains(string: '1 appointment decision(s) repointed'));

		$this->migration->run(output: $this->output);

		self::assertSame(
			expected: [['id' => 'd-1', 'title' => 'Benoeming', 'targetPosts' => ['position-type-1', 'pt-already']]],
			actual: $service->savedFor('decision'),
		);
		self::assertSame(expected: ['d-1'], actual: $service->uuidsFor('decision'));

	}//end testAnAppointmentsTargetPostsArePointedAtThePositionTypes()

	/**
	 * A second run copies nothing, but still knows the copied positions to hold and repoint by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testASecondRunCopiesNothingAgain(): void {
		$service = $this->makeObjectService(rows: [
			'post' => [['id' => 'post-1', 'label' => 'Voorzitter', 'governanceBody' => 'raad']],
			'position-type' => [['id' => 'pt-1', 'name' => 'Voorzitter', 'governanceBody' => 'uuid-of-raad', 'migratedFromObject' => 'post-1']],
			'membership' => [
				['id' => 'm-1', 'post' => 'post-1', 'startDate' => '2024-01-01'],
				['id' => 'm-2', 'post' => 'post-1', 'startDate' => '2025-01-01'],
			],
			'position-hold' => [['id' => 'ph-1', 'membership' => 'm-1', 'migratedFromObject' => 'm-1']],
		]);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertSame(expected: [], actual: $service->savedFor('position-type'));
		// Only the membership not copied before gets a hold, on the position copied before.
		self::assertSame(
			expected: [['membership' => 'm-2', 'position' => 'pt-1', 'holdType' => 'regular', 'startDate' => '2025-01-01', 'migratedFromObject' => 'm-2', 'governanceBody' => 'uuid-of-raad']],
			actual: $service->savedFor('position-hold'),
		);

	}//end testASecondRunCopiesNothingAgain()

	/**
	 * A refused save is reported as a warning and not counted; the run goes on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testARefusedSaveIsReportedAndNotCounted(): void {
		$service = $this->makeObjectService(
			rows: [
				'post' => [
					['id' => 'post-1', 'label' => 'Kapot', 'governanceBody' => 'raad'],
					['id' => 'post-2', 'label' => 'Heel', 'governanceBody' => 'raad'],
				],
			],
			refuse: 'Kapot',
		);
		$this->container->method('get')->willReturn($service);
		$this->output->expects(self::once())->method('warning')->with(self::stringContains(string: 'refused'));
		$this->output->expects(self::once())->method('info')->with(self::stringContains(string: '1 position type(s)'));

		$this->migration->run(output: $this->output);

		self::assertSame(expected: ['Heel'], actual: array_column($service->savedFor('position-type'), 'name'));

	}//end testARefusedSaveIsReportedAndNotCounted()

	/**
	 * Nothing runs when OpenRegister is unavailable.
	 *
	 * @return void
	 *
	 * @spec exclude Guard clause; asserts the migration is inert without OpenRegister.
	 */
	public function testNothingRunsWithoutOpenRegister(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects(self::never())->method('get');

		(new MigratePostsToPositionHolds(settingsService: $settings, container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class)))
			->run(output: $this->output);

	}//end testNothingRunsWithoutOpenRegister()

	/**
	 * A fake ObjectService over rows per schema, recording what the migration writes.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows   The stored rows per schema.
	 * @param string                                       $refuse A name whose save is refused.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(array $rows = [], string $refuse = ''): object {
		return new class($rows, $refuse) {
			/**
			 * The schema currently selected.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Saves, as [schema, payload, uuid] triples.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>,2:string|null}>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $rows   The stored rows per schema.
			 * @param string                                       $refuse A name whose save is refused.
			 *
			 * @return void
			 */
			public function __construct(
				private array $rows,
				private string $refuse,
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
			 * The uuids the saves for one schema updated.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,string|null> The uuids.
			 */
			public function uuidsFor(string $schema): array {
				$out = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $schema) {
						$out[] = $save[2];
					}
				}

				return $out;

			}//end uuidsFor()

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
			 * Return the rows for the selected schema, or the uuid of a looked-up slug.
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

				return ($this->rows[$this->currentSchema] ?? []);

			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 * @param string|null         $uuid     The object to update.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): array {
				if ($this->refuse !== '' && ($object['name'] ?? null) === $this->refuse) {
					throw new RuntimeException('refused by validation');
				}

				$this->saves[] = [$schema, $object, $uuid];

				return (['id' => ($uuid ?? $schema . '-' . count($this->savedFor($schema)))] + $object);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
