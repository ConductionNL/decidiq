<?php

/**
 * Unit tests for MigrateIntegrityDisclosures.
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

use OCA\Decidiq\Migration\MigrateIntegrityDisclosures;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The rename of ancillary positions and gifts, and the fold of the integrity policy.
 *
 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md
 */
class MigrateIntegrityDisclosuresTest extends TestCase {

	private const BODY = '11111111-1111-4111-8111-111111111111';

	private const PERSON = '22222222-2222-4222-8222-222222222222';

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settingsService;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Output mock.
	 *
	 * @var IOutput
	 */
	private IOutput $output;

	/**
	 * The migration under test.
	 *
	 * @var MigrateIntegrityDisclosures
	 */
	private MigrateIntegrityDisclosures $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container       = $this->createMock(originalClassName: ContainerInterface::class);
		$this->output          = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateIntegrityDisclosures(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * An ancillary position keeps its values and fits the shipped schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-existing-disclosures-are-carried-across
	 */
	public function testAnAncillaryPositionIsCarriedAcross(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: [
				'nevenfunctie' => [
					[
						'id' => 'nf-1',
						'person' => self::PERSON,
						// A slug, as the seeds carry it: resolved to a uuid on the way.
						'governanceBody' => 'gemeenteraad',
						'organisation' => 'Stichting Wijkbelang',
						'role' => 'Voorzitter',
						'remunerated' => false,
						'startDate' => '2025-01-01',
						'declaredAt' => '2025-01-10',
						'lifecycle' => 'public',
						'hoursIndication' => '',
					],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$written = $service->writtenFor('ancillary-position');
		self::assertCount(expectedCount: 1, haystack: $written);
		$row = $written[0];
		self::assertSame(expected: 'uuid-of-gemeenteraad', actual: $row['governanceBody']);
		self::assertSame(expected: self::PERSON, actual: $row['person']);
		self::assertSame(expected: 'Stichting Wijkbelang', actual: $row['organisation']);
		self::assertFalse(condition: $row['remunerated']);
		self::assertSame(expected: 'nf-1', actual: $row['migratedFromObject']);
		self::assertArrayNotHasKey(key: 'hoursIndication', array: $row);
		self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'ancillary-position', payload: $row));

	}//end testAnAncillaryPositionIsCarriedAcross()

	/**
	 * A declared gift keeps its values and fits the shipped schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-existing-disclosures-are-carried-across
	 */
	public function testADeclaredGiftIsCarriedAcross(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: [
				'geschenk' => [
					[
						'id' => 'gs-1',
						'recipient' => self::PERSON,
						'governanceBody' => self::BODY,
						'type' => 'gift',
						'giver' => 'Bouwbedrijf Noord',
						'description' => 'Kerstpakket',
						'estimatedValue' => 45.5,
						'receivedOn' => '2025-12-20',
						'decision' => 'refused',
						'declaredAt' => '2025-12-21',
					],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$row = $service->writtenFor('declared-gift')[0];
		self::assertSame(expected: self::PERSON, actual: $row['recipient']);
		self::assertSame(expected: 45.5, actual: $row['estimatedValue']);
		self::assertSame(expected: 'refused', actual: $row['decision']);
		self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'declared-gift', payload: $row));

	}//end testADeclaredGiftIsCarriedAcross()

	/**
	 * A policy folds into the body's existing configuration, sent whole.
	 *
	 * The update names the configuration it updates and carries
	 * `governanceBody`, because OpenRegister validates a patch as a whole object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-the-integrity-policy-is-body-configuration
	 */
	public function testAPolicyFoldsIntoTheExistingConfiguration(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: [
				'integriteitsbeleid' => [
					[
						'id' => 'ib-1',
						'governanceBody' => self::BODY,
						'ancillaryPositionDisclosureDefault' => 'public',
						'giftThresholdAmount' => 50,
						'giftsPublic' => true,
						'integrityNotificationGroup' => '',
					],
				],
			],
			existing: ['body-governance-configuration' => [['id' => 'cfg-1', 'governanceBody' => self::BODY]]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$saved = $service->savedFor('body-governance-configuration');
		self::assertCount(expectedCount: 1, haystack: $saved);
		self::assertSame(expected: 'cfg-1', actual: $saved[0]['id']);
		$written = $service->writtenFor('body-governance-configuration')[0];
		self::assertSame(expected: self::BODY, actual: $written['governanceBody']);
		self::assertSame(expected: 'public', actual: $written['ancillaryPositionDisclosureDefault']);
		self::assertArrayNotHasKey(key: 'integrityNotificationGroup', array: $written);
		self::assertSame(
			expected: [],
			actual: MergedRegisterSchema::violations(slug: 'body-governance-configuration', payload: $written)
		);

	}//end testAPolicyFoldsIntoTheExistingConfiguration()

	/**
	 * A policy for a body without a configuration creates one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-the-integrity-policy-is-body-configuration
	 */
	public function testAPolicyWithoutAConfigurationCreatesOne(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: [
				'integriteitsbeleid' => [
					['id' => 'ib-1', 'governanceBody' => self::BODY, 'giftsPublic' => false],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$saved = $service->savedFor('body-governance-configuration');
		self::assertCount(expectedCount: 1, haystack: $saved);
		self::assertStringStartsWith(prefix: 'new-', string: $saved[0]['id']);
		self::assertFalse(condition: $saved[0]['giftsPublic']);

	}//end testAPolicyWithoutAConfigurationCreatesOne()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-existing-disclosures-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: ['geschenk' => [['id' => 'gs-1', 'giver' => 'Al gedaan']]],
			existing: ['declared-gift' => [['id' => 'dg-1', 'migratedFromObject' => 'gs-1']]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->writtenFor('declared-gift'));

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
	 * @param array<string,array<int,array<string,mixed>>> $legacy   Legacy rows, by source schema.
	 * @param array<string,array<int,array<string,mixed>>> $existing     Rows already copied, by schema.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $legacy = [],
		array $existing = [],
	): object {
		return new class($legacy, $existing) {
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
			 * @param array<string,array<int,array<string,mixed>>> $legacy   Legacy rows, by source schema.
			 * @param array<string,array<int,array<string,mixed>>> $existing     Rows already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $legacy,
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

				if (isset($this->legacy[$this->currentSchema]) === true) {
					return $this->legacy[$this->currentSchema];
				}

				return array_merge(
					($this->existing[$this->currentSchema] ?? []),
					$this->savedFor($this->currentSchema)
				);

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
			 * @param string|null         $uuid     The object updated, null for a new one.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): array {
				$id = ($uuid ?? ('new-' . $schema . '-' . (count($this->saves) + 1)));
				$this->saves[] = [$schema, $object, $id];

				return ($object + ['id' => $id]);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
