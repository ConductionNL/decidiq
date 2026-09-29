<?php

/**
 * Unit tests for publishing the papers with the agenda (publication-papers-and-search).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaPapers;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IAppConfig;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publishing an agenda publishes the papers of its public items and leaves
 * out every item under an active confidentiality restriction.
 *
 * @covers \OCA\Decidiq\Service\PublicationPayloadService
 * @covers \OCA\Decidiq\Service\PublicationConfigService
 * @covers \OCA\Decidiq\Service\AgendaPapers
 * @covers \OCA\Decidiq\Service\PublicationService
 * @covers \OCA\Decidiq\Service\PublicationRepository
 * @uses   \OCA\Decidiq\Service\SettingsService
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
 */
class PublicationPapersTest extends TestCase {

	/**
	 * The files handed to publishFile(), as "item/file".
	 *
	 * @var list<string>
	 */
	private array $publishedFiles = [];

	/**
	 * The findAll() configs the object service received.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * Objects saved through the object service, by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $store = [];

	/**
	 * The files handed to unpublishFile(), as "item/file".
	 *
	 * @var list<string>
	 */
	private array $unpublishedFiles = [];

	/**
	 * The object service double of the last service().
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * An entity double serializing to $data.
	 *
	 * @param array<string,mixed> $data The object
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A file node double.
	 *
	 * @param array<string,mixed> $file id, name and optional labels
	 *
	 * @return File&MockObject
	 */
	private function node(array $file): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($file['id']);
		$node->method('getName')->willReturn($file['name']);
		return $node;
	}//end node()

	/**
	 * The service over OpenRegister holding the council agenda of 14 October,
	 * $restrictions and $files per agenda item.
	 *
	 * @param list<array<string,mixed>>|null           $restrictions Confidentiality restrictions, null when the read fails
	 * @param array<string, list<array<string,mixed>>> $files        Files per item id
	 *
	 * @return PublicationPayloadService
	 */
	private function service(?array $restrictions, array $files): PublicationPayloadService {
		$items = [
			['id' => 'item-1', 'title' => 'Housing plan', 'orderNumber' => 1, 'meeting' => 'meeting-1'],
			['id' => 'item-2', 'title' => 'Land purchase Noordkade', 'orderNumber' => 2, 'meeting' => 'meeting-1'],
			['id' => 'item-3', 'title' => 'Budget amendment', 'orderNumber' => 3, 'meeting' => 'meeting-1'],
			['id' => 'item-4', 'title' => 'Staff matter Janssen', 'orderNumber' => 4, 'meeting' => 'meeting-1'],
		];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($items, $restrictions): array {
				$this->queries[] = $config;
				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? null) !== 'decidiq') {
					return [];
				}

				if (($filters['schema'] ?? null) === 'agenda-item' && ($filters['meeting'] ?? null) === 'meeting-1') {
					return array_map(fn (array $i) => $this->entity($i), $items);
				}

				if (($filters['schema'] ?? null) === 'confidentiality-restriction') {
					if ($restrictions === null) {
						throw new RuntimeException('database gone');
					}

					return array_map(fn (array $r) => $this->entity($r), $restrictions);
				}

				return [];
			}
		);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?object => isset($this->store[(string)$id]) ? $this->entity($this->store[(string)$id]) : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): object {
				$uuid = ($uuid ?? ('obj-' . (count($this->store) + 1)));
				$this->store[$uuid] = array_merge(['id' => $uuid, '_schema' => $schema], $object);
				return $this->entity($this->store[$uuid]);
			}
		);
		$this->objects = $objects;

		$fileService = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getFiles', 'publishFile', 'unpublishFile', 'formatFile'])
			->getMock();
		$fileService->method('getFiles')->willReturnCallback(
			fn (ObjectEntity|string $object): array => array_map(fn (array $f) => $this->node($f), ($files[(string)$object] ?? []))
		);
		$fileService->method('publishFile')->willReturnCallback(
			function (ObjectEntity|string $object, string|int $file) use ($files): File {
				$this->publishedFiles[] = $object . '/' . $file;
				foreach ($files[(string)$object] as $f) {
					if ($f['id'] === $file) {
						return $this->node($f);
					}
				}

				throw new RuntimeException('no such file');
			}
		);
		$fileService->method('unpublishFile')->willReturnCallback(
			function (ObjectEntity|string $object, string|int $file): File {
				$this->unpublishedFiles[] = $object . '/' . $file;
				return $this->node(['id' => (int)$file, 'name' => 'x']);
			}
		);
		$labels = [];
		foreach ($files as $list) {
			foreach ($list as $f) {
				$labels[$f['id']] = ($f['labels'] ?? []);
			}
		}

		$fileService->method('formatFile')->willReturnCallback(
			static fn ($node): array => [
				'id' => $node->getId(),
				'path' => '/decidiq/Agenda items/' . $node->getName(),
				'title' => $node->getName(),
				'accessUrl' => 'https://raad.example/s/' . $node->getId(),
				'downloadUrl' => 'https://raad.example/s/' . $node->getId() . '/download',
				'type' => 'application/pdf',
				'extension' => 'pdf',
				'size' => 1024,
				'hash' => 'etag',
				'labels' => $labels[$node->getId()],
			]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === 'OCA\OpenRegister\Service\FileService') ? $fileService : $objects
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$logger = $this->createMock(LoggerInterface::class);
		return new PublicationPayloadService(
			$container,
			$logger,
			new PublicationConfigService($appConfig),
			new AgendaPapers($container, $logger),
		);
	}//end service()

	/**
	 * The meeting as the publication path hands it over: no inline items.
	 *
	 * @return array<string,mixed>
	 */
	private function meeting(): array {
		return [
			'id' => 'meeting-1',
			'title' => 'Council meeting',
			'scheduledDate' => '2026-10-14T19:30:00+02:00',
			'meetingType' => 'regular',
			'bodyName' => 'Municipal council',
		];
	}//end meeting()

	/**
	 * Papers on every item; item 2 under an imposed restriction, item 3's
	 * restriction dissolved, item 4's ratified; one paper of item 3 labelled
	 * confidential.
	 *
	 * @return PublicationPayloadService
	 */
	private function councilAgenda(): PublicationPayloadService {
		return $this->service(
			restrictions: [
				['scope' => 'item', 'targetAgendaItem' => 'item-2', 'lifecycle' => 'imposed'],
				['scope' => 'item', 'targetAgendaItem' => 'item-3', 'lifecycle' => 'dissolved'],
				['scope' => 'item', 'targetAgendaItem' => 'item-4', 'lifecycle' => 'ratified'],
			],
			files: [
				'item-1' => [['id' => 11, 'name' => 'housing-plan.pdf']],
				'item-2' => [['id' => 21, 'name' => 'purchase-price.pdf']],
				'item-3' => [
					['id' => 31, 'name' => 'amendment.pdf'],
					['id' => 32, 'name' => 'legal-advice.pdf', 'labels' => ['Confidential']],
				],
				'item-4' => [['id' => 41, 'name' => 'personnel-file.pdf']],
			],
		);
	}//end councilAgenda()

	/**
	 * A resident gets the papers of the public items and nothing of an item
	 * under an imposed or ratified restriction; the publication names body,
	 * date and document type.
	 *
	 * @return void
	 */
	public function testPapersOfPublicItemsArePublished(): void {
		$payload = $this->councilAgenda()->build('agenda', $this->meeting(), null, 1);

		self::assertSame(['Housing plan', 'Budget amendment'], array_column($payload['agendaItems'], 'title'));
		self::assertSame(
			[['title' => 'housing-plan.pdf', 'url' => 'https://raad.example/s/11/download', 'type' => 'application/pdf']],
			$payload['agendaItems'][0]['papers']
		);
		self::assertSame(['amendment.pdf'], array_column($payload['agendaItems'][1]['papers'], 'title'));
		self::assertSame(['item-1/11', 'item-3/31'], $this->publishedFiles);
		self::assertSame('Municipal council', $payload['bodyName']);
		self::assertSame('2026-10-14T19:30:00+02:00', $payload['meetingDate']);
		self::assertSame('agenda', $payload['documentType']);

		// The paper refs are internal: PublicationService moves them onto the
		// record before the payload is stored (asserted in the withdraw test).
		self::assertSame([['agendaItem' => 'item-1', 'file' => 11], ['agendaItem' => 'item-3', 'file' => 31]], $payload['_publishedPapers']);
		unset($payload['_publishedPapers']);

		$encoded = (string)json_encode($payload);
		foreach (['Noordkade', 'purchase-price', 'Janssen', 'personnel-file', 'legal-advice', 'item-'] as $secret) {
			self::assertStringNotContainsString($secret, $encoded);
		}
	}//end testPapersOfPublicItemsArePublished()

	/**
	 * The service asks OpenRegister for the meeting's items and the
	 * restrictions with the register and schema inside the filters, where
	 * ObjectService reads them, and reads restrictions in system context.
	 *
	 * @return void
	 */
	public function testItemsAndRestrictionsAreReadWhereOpenRegisterLooks(): void {
		$this->councilAgenda()->build('agenda', $this->meeting(), null, 1);

		$schemas = array_map(static fn (array $q): ?string => ($q['filters']['schema'] ?? null), $this->queries);
		self::assertContains('agenda-item', $schemas);
		self::assertContains('confidentiality-restriction', $schemas);
	}//end testItemsAndRestrictionsAreReadWhereOpenRegisterLooks()

	/**
	 * When the restrictions cannot be read the agenda is not published at all:
	 * no payload, no paper made public.
	 *
	 * @return void
	 */
	public function testRestrictionsThatCannotBeReadStopThePublication(): void {
		$service = $this->service(restrictions: null, files: ['item-1' => [['id' => 11, 'name' => 'housing-plan.pdf']]]);

		try {
			$service->build('agenda', $this->meeting(), null, 1);
			self::fail('An agenda whose restrictions could not be read was published.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('confidential', $e->getMessage());
		}

		self::assertSame([], $this->publishedFiles);
	}//end testRestrictionsThatCannotBeReadStopThePublication()

	/**
	 * Every publication names its document type, so citizens can filter on it.
	 *
	 * @return void
	 */
	public function testEveryPublicationNamesItsDocumentType(): void {
		$service = $this->service(restrictions: [], files: []);

		self::assertSame('decision', $service->build('decision', ['title' => 'Housing plan adopted'], null, 1)['documentType']);
		self::assertSame('minutes', $service->build('minutes', ['title' => 'Minutes 14 October'], null, 1)['documentType']);
	}//end testEveryPublicationNamesItsDocumentType()

	/**
	 * The agenda payload, with papers, validates against the shipped
	 * publication-payload schema (base register plus every fragment).
	 *
	 * @return void
	 */
	public function testThePayloadValidatesAgainstTheShippedSchema(): void {
		$payload = $this->councilAgenda()->build('agenda', $this->meeting(), null, 1);
		unset($payload['_publishedPapers']);
		$payload['publicationDate'] = '2026-10-10T09:00:00+02:00';

		$schema = null;
		foreach (SettingsService::shippedRegisterDescriptor()['components']['schemas'] as $candidate) {
			if (($candidate['slug'] ?? null) === 'publication-payload') {
				$schema = $candidate;
			}
		}

		self::assertIsArray($schema);
		$properties = $schema['properties'];
		foreach (array_keys($properties) as $key) {
			unset($properties[$key]['facetable']);
		}

		$strictItems = $properties['agendaItems'];
		$strictItems['items']['additionalProperties'] = false;
		$strictItems['items']['properties']['papers']['items']['additionalProperties'] = false;
		$properties['agendaItems'] = $strictItems;

		$result = (new Validator())->validate(
			json_decode((string)json_encode($payload)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'additionalProperties' => false]))
		);

		self::assertTrue($result->isValid(), (string)json_encode($result->error()?->keyword()));
	}//end testThePayloadValidatesAgainstTheShippedSchema()

	/**
	 * The publication service over $payload, with the meeting eligible.
	 *
	 * @param PublicationPayloadService $payload The payload service
	 *
	 * @return PublicationService
	 */
	private function publicationService(PublicationPayloadService $payload): PublicationService {
		$eligibility = $this->createMock(PublicationEligibilityService::class);
		$eligibility->method('assertEligible')->willReturn($this->meeting());
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => '']);

		return new PublicationService(
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppManager::class),
			$eligibility,
			$payload,
			new PublicationConfigService($appConfig),
			$this->createMock(OpenCatalogiPublisher::class),
			$audit,
			$this->objects,
		);
	}//end publicationService()

	/**
	 * The record keeps which papers were made public, the public payload does
	 * not, and withdrawing the publication takes those papers offline.
	 *
	 * @return void
	 */
	public function testWithdrawingTheAgendaTakesItsPapersOffline(): void {
		$service = $this->publicationService($this->councilAgenda());

		$record = $service->publish('agenda', 'meeting-1', 'griffier')['record'];

		self::assertSame(
			[['agendaItem' => 'item-1', 'file' => 11], ['agendaItem' => 'item-3', 'file' => 31]],
			$record['publishedPapers']
		);
		self::assertArrayNotHasKey('_publishedPapers', $this->store[$record['payloadObject']]);
		self::assertArrayNotHasKey('publishedPapers', $this->store[$record['payloadObject']]);

		$service->withdraw($record['id'], 'griffier', 'Wrong papers');

		self::assertSame(['item-1/11', 'item-3/31'], $this->unpublishedFiles);
	}//end testWithdrawingTheAgendaTakesItsPapersOffline()

	/**
	 * Rectifying keeps the papers the new version still publishes online.
	 *
	 * @return void
	 */
	public function testRectifyingKeepsThePapersTheNewVersionPublishes(): void {
		$service = $this->publicationService($this->councilAgenda());
		$record  = $service->publish('agenda', 'meeting-1', 'griffier')['record'];

		$service->rectify($record['id'], 'griffier', 'Title corrected');

		self::assertSame([], $this->unpublishedFiles);
	}//end testRectifyingKeepsThePapersTheNewVersionPublishes()
}//end class
