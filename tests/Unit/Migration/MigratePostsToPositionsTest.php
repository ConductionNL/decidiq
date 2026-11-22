<?php

/**
 * Unit tests for MigratePostsToPositions.
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

use OCA\Decidiq\Migration\MigratePostsToPositions;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The move of posts onto positions and position holds.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class MigratePostsToPositionsTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private IOutput $output;

	private MigratePostsToPositions $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container       = $this->createMock(originalClassName: ContainerInterface::class);
		$this->output          = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigratePostsToPositions(
			$this->settingsService,
			$this->container,
			$this->createMock(originalClassName: LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * A post becomes a named position on its body, and its holder a hold on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAPostBecomesAPositionAndItsHolderAHold(): void {
		$service = $this->run_(
			rows: [
				'post' => [
					[
						'id' => 'post-1',
						'label' => 'Voorzitter gemeenteraad',
						'role' => 'chair',
						'startDate' => '2018-05-30T00:00:00Z',
						'endDate' => '2022-03-30T00:00:00Z',
						'governanceBody' => 'gemeenteraad',
					],
				],
				'membership' => [
					['id' => 'm-1', 'governanceBody' => 'gemeenteraad', 'startDate' => '2014-03-27', 'post' => 'post-1'],
					['id' => 'm-2', 'governanceBody' => 'gemeenteraad', 'startDate' => '2014-03-27'],
				],
			]
		);

		$types = $service->savedFor('position-type');
		self::assertCount(expectedCount: 1, haystack: $types);
		self::assertSame(
			expected: [
				'name' => 'Voorzitter gemeenteraad',
				'key' => 'chair',
				'governanceBody' => 'uuid-of-gemeenteraad',
				'migratedFromObject' => 'post-1',
			],
			actual: $types[0]
		);

		$holds = $service->savedFor('position-hold');
		self::assertCount(expectedCount: 1, haystack: $holds);
		// 🔑 THE POST'S DATES DESCRIBED THE HOLDER, so they become the hold's,
		// as calendar days.
		self::assertSame(
			expected: [
				'membership' => 'm-1',
				'position' => 'position-type-1',
				'governanceBody' => 'uuid-of-gemeenteraad',
				'holdType' => 'regular',
				'startDate' => '2018-05-30',
				'endDate' => '2022-03-30',
			],
			actual: $holds[0]
		);

	}//end testAPostBecomesAPositionAndItsHolderAHold()

	/**
	 * A post naming no body is skipped, and so is its holder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAPostWithNoBodyIsSkipped(): void {
		$service = $this->run_(
			rows: [
				'post' => [['id' => 'post-1', 'label' => 'Chair']],
				'membership' => [['id' => 'm-1', 'startDate' => '2020-01-01', 'post' => 'post-1']],
			]
		);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('position-type'));
		self::assertCount(expectedCount: 0, haystack: $service->savedFor('position-hold'));

	}//end testAPostWithNoBodyIsSkipped()

	/**
	 * Without a start date on the post or the membership, no hold is invented.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testNoStartDateWritesNoHold(): void {
		$service = $this->run_(
			rows: [
				'post' => [['id' => 'post-1', 'label' => 'Chair', 'governanceBody' => 'raad']],
				'membership' => [['id' => 'm-1', 'governanceBody' => 'raad', 'post' => 'post-1']],
			]
		);

		self::assertCount(expectedCount: 1, haystack: $service->savedFor('position-type'));
		self::assertCount(expectedCount: 0, haystack: $service->savedFor('position-hold'));

	}//end testNoStartDateWritesNoHold()

	/**
	 * A post without dates takes the membership's start.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testTheMembershipStartIsUsedWhenThePostHasNone(): void {
		$hold = MigratePostsToPositions::holdPayload(
			membership: ['startDate' => '2025-04-01'],
			membershipId: 'm-1',
			post: ['label' => 'OR-voorzitter'],
			position: 'pt-1',
			body: 'b-1'
		);

		self::assertSame(expected: '2025-04-01', actual: $hold['startDate'] ?? null);
		self::assertArrayNotHasKey(key: 'endDate', array: (array)$hold);

	}//end testTheMembershipStartIsUsedWhenThePostHasNone()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testASecondRunCopiesNothing(): void {
		$service = $this->run_(
			rows: [
				'post' => [['id' => 'post-1', 'label' => 'Chair', 'governanceBody' => 'raad', 'startDate' => '2020-01-01']],
				'position-type' => [['id' => 'pt-1', 'migratedFromObject' => 'post-1']],
				'position-hold' => [['id' => 'h-1', 'membership' => 'm-1', 'position' => 'pt-1']],
				'membership' => [['id' => 'm-1', 'governanceBody' => 'raad', 'post' => 'post-1']],
				'decision' => [['id' => 'd-1', 'targetPosts' => ['pt-1']]],
			]
		);

		self::assertSame(expected: [], actual: $service->saves);

	}//end testASecondRunCopiesNothing()

	/**
	 * An appointment's targets are repointed at the copied positions, in order.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAnAppointmentIsRepointedAtThePositions(): void {
		$service = $this->run_(
			rows: [
				'post' => [
					['id' => 'post-1', 'label' => 'Chair', 'governanceBody' => 'raad'],
					['id' => 'post-2', 'label' => 'Secretary', 'governanceBody' => 'raad'],
				],
				// post-2 was copied on an earlier run.
				'position-type' => [['id' => 'pt-2', 'migratedFromObject' => 'post-2']],
				'decision' => [
					['id' => 'd-1', '@self' => ['id' => 'd-1'], 'title' => 'Benoeming', 'targetPosts' => ['post-1', 'post-2']],
					['id' => 'd-2', 'targetPosts' => []],
				],
			]
		);

		$decisions = $service->savedFor('decision');
		self::assertCount(expectedCount: 1, haystack: $decisions);
		self::assertSame(expected: ['position-type-1', 'pt-2'], actual: $decisions[0]['targetPosts']);
		self::assertSame(expected: 'Benoeming', actual: $decisions[0]['title']);
		self::assertArrayNotHasKey(key: '@self', array: $decisions[0]);
		self::assertSame(expected: 'd-1', actual: $service->uuids[0] ?? null);

	}//end testAnAppointmentIsRepointedAtThePositions()

	/**
	 * A list with a target that maps nowhere is left whole.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAHalfMappableListIsLeftWhole(): void {
		self::assertNull(MigratePostsToPositions::repointedTargets(targets: ['post-1', 'post-gone'], positions: ['post-1' => 'pt-1']));
		self::assertNull(MigratePostsToPositions::repointedTargets(targets: [], positions: ['post-1' => 'pt-1']));
		self::assertNull(MigratePostsToPositions::repointedTargets(targets: ['pt-1'], positions: ['post-1' => 'pt-1']));
		self::assertSame(
			expected: ['pt-1', 'pt-1'],
			actual: MigratePostsToPositions::repointedTargets(targets: ['post-1', 'pt-1'], positions: ['post-1' => 'pt-1'])
		);

	}//end testAHalfMappableListIsLeftWhole()

	/**
	 * A post with a role and no label is named after its role.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAnUnlabelledPostIsNamedAfterItsRole(): void {
		self::assertSame(
			expected: 'Vice chair',
			actual: MigratePostsToPositions::positionPayload(post: ['role' => 'vice-chair'], body: 'b', origin: 'p')['name']
		);

	}//end testAnUnlabelledPostIsNamedAfterItsRole()

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
	 * Run the migration over a fake store holding the given rows.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Stored rows by schema.
	 *
	 * @return object The fake store, after the run.
	 */
	private function run_(array $rows): object {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(rows: $rows);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		return $service;

	}//end run_()

	/**
	 * A fake ObjectService recording what the migration writes.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Stored rows by schema.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(array $rows): object {
		return new class($rows) {
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
			 * The uuid of every update, in order.
			 *
			 * @var array<int,string>
			 */
			public array $uuids = [];

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $rows Stored rows by schema.
			 *
			 * @return void
			 */
			public function __construct(
				private array $rows,
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
			 * Return the rows for the selected schema; a slug lookup answers `uuid-of-<slug>`.
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
			 * @param array<string,mixed> $object  The payload.
			 * @param ObjectSaveOptions   $options The target.
			 * @param bool                $_rbac   Unused.
			 * @param bool                $_multitenancy Unused.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$schema        = $options->target->schema;
				$this->saves[] = [$schema, $object];
				if ($options->uuid !== null) {
					$this->uuids[] = $options->uuid;
					return $object;
				}

				return ($object + ['id' => $schema . '-' . count($this->savedFor($schema))]);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
