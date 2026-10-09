<?php

/**
 * Unit tests for MigrateDocumentsToAgendaItems.
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

use OCA\Decidiq\Migration\AgendaItemTypeResolver;
use OCA\Decidiq\Migration\MigrateDocumentsToAgendaItems;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Incoming documents, information letters and technical questions become agenda items.
 *
 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md
 */
class MigrateDocumentsToAgendaItemsTest extends TestCase {

	private const BODY = '11111111-1111-4111-8111-111111111111';

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
	 * @var MigrateDocumentsToAgendaItems
	 */
	private MigrateDocumentsToAgendaItems $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container       = $this->createMock(originalClassName: ContainerInterface::class);
		$this->output          = $this->createMock(originalClassName: IOutput::class);
		$logger                = $this->createMock(originalClassName: LoggerInterface::class);

		$this->migration = new MigrateDocumentsToAgendaItems(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $logger,
			types: new AgendaItemTypeResolver(logger: $logger),
		);

	}//end setUp()

	/**
	 * An incoming document becomes a typed informational agenda item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md#requirement-a-routed-document-is-an-agenda-item
	 */
	public function testAnIncomingDocumentBecomesAnAgendaItem(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: [
				'ingekomen-stuk' => [
					[
						'id' => 'is-1',
						'title' => 'Brief van de provincie',
						'directedTo' => self::BODY,
						'sender' => 'Provincie Utrecht',
						'receivedAt' => '2026-03-02',
						'routingAdvice' => '',
						'lifecycle' => 'registered',
					],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$items = $service->writtenFor('agenda-item');
		self::assertCount(expectedCount: 1, haystack: $items);
		$item = $items[0];
		self::assertSame(expected: 'Brief van de provincie', actual: $item['title']);
		self::assertSame(expected: 'informational', actual: $item['itemType']);
		self::assertNotSame(expected: '', actual: $item['type']);
		self::assertSame(expected: 'Provincie Utrecht', actual: $item['typeFields']['sender']);
		self::assertSame(expected: 'is-1', actual: $item['typeFields']['migratedFromObject']);
		self::assertArrayNotHasKey(key: 'routingAdvice', array: $item['typeFields']);
		self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'agenda-item', payload: $item));

		$types = $service->writtenFor('agenda-item-type');
		self::assertCount(expectedCount: 1, haystack: $types);
		self::assertSame(expected: 'Incoming document', actual: $types[0]['name']);

	}//end testAnIncomingDocumentBecomesAnAgendaItem()

	/**
	 * A technical question becomes a sub-item of the copied information letter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md#requirement-a-question-about-a-document-is-a-sub-item-of-it
	 */
	public function testAQuestionPointsAtItsCopiedLetter(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: [
				'raadsinformatiebrief' => [
					['id' => 'rib-1', 'subject' => 'Stand van zaken jeugdzorg', 'directedTo' => self::BODY],
				],
				'technische-vraag' => [
					['id' => 'tv-1', 'question' => 'Hoeveel jongeren staan op de wachtlijst?', 'rib' => 'rib-1'],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$saved  = $service->savedFor('agenda-item');
		$byFrom = [];
		foreach ($saved as $item) {
			$byFrom[$item['typeFields']['migratedFromObject']] = $item;
		}

		self::assertCount(expectedCount: 2, haystack: $saved);
		self::assertSame(expected: $byFrom['rib-1']['id'], actual: $byFrom['tv-1']['parentItem']);
		self::assertSame(expected: 'discussion', actual: $byFrom['tv-1']['itemType']);
		self::assertSame(
			expected: 'Hoeveel jongeren staan op de wachtlijst?',
			actual: $byFrom['tv-1']['typeFields']['question']
		);
		foreach ($service->writtenFor('agenda-item') as $item) {
			self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'agenda-item', payload: $item));
		}

	}//end testAQuestionPointsAtItsCopiedLetter()

	/**
	 * A second run copies nothing; the origin lives in typeFields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md#requirement-existing-documents-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$service = $this->makeObjectService(
			legacy: ['ingekomen-stuk' => [['id' => 'is-1', 'title' => 'Al gedaan']]],
			existing: ['agenda-item' => [['id' => 'ai-1', 'typeFields' => ['migratedFromObject' => 'is-1']]]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->writtenFor('agenda-item'));

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
