<?php

/**
 * Refusal tests: a confidential agenda item never reaches the public.
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
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Confidentiality lives in confidentiality-restriction objects (scope item,
 * targetAgendaItem, lifecycle imposed / ratified / dissolved). Publishing an
 * agenda reads them; an item under an imposed or ratified restriction reaches
 * no public surface, and restrictions that cannot be read stop the
 * publication.
 *
 * The object service double answers agenda item queries whether register and
 * schema sit in the filters or beside them, so these tests prove the
 * confidentiality refusal on its own, apart from the query placement.
 *
 * @covers \OCA\Decidiq\Service\PublicationService
 * @covers \OCA\Decidiq\Service\PublicationPayloadService
 * @covers \OCA\Decidiq\Service\AgendaPapers
 * @covers \OCA\Decidiq\Service\PublicationRepository
 * @covers \OCA\Decidiq\Service\PublicationConfigService
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-002-a-confidential-item-never-reaches-the-public
 */
class PublicationConfidentialityTest extends TestCase {

	/**
	 * Everything the catalogue publisher was handed, JSON encoded.
	 *
	 * @var list<string>
	 */
	private array $catalogued = [];

	/**
	 * The files handed to publishFile(), as "item/file".
	 *
	 * @var list<string>
	 */
	private array $publishedFiles = [];

	/**
	 * Objects saved through the object service, by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $store = [];

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
	 * @param int    $id   File id
	 * @param string $name File name
	 *
	 * @return File&MockObject
	 */
	private function node(int $id, string $name): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		return $node;
	}//end node()

	/**
	 * The publication service over the council agenda of 14 October: item 1
	 * public, item 2 under an imposed restriction, item 3 under a dissolved
	 * one, item 4 under a ratified one; one paper per item.
	 *
	 * @param list<array<string,mixed>>|null $restrictions The restrictions, null when reading them fails
	 *
	 * @return PublicationService
	 */
	private function service(?array $restrictions): PublicationService {
		$items = [
			['id' => 'item-1', 'title' => 'Housing plan', 'orderNumber' => 1, 'meeting' => 'meeting-1'],
			['id' => 'item-2', 'title' => 'Land purchase Noordkade', 'orderNumber' => 2, 'meeting' => 'meeting-1'],
			['id' => 'item-3', 'title' => 'Budget amendment', 'orderNumber' => 3, 'meeting' => 'meeting-1'],
			['id' => 'item-4', 'title' => 'Staff matter Janssen', 'orderNumber' => 4, 'meeting' => 'meeting-1'],
		];
		$files = [
			'item-1' => [11 => 'housing-plan.pdf'],
			'item-2' => [21 => 'purchase-price-noordkade.pdf'],
			'item-3' => [31 => 'amendment.pdf'],
			'item-4' => [41 => 'personnel-file-janssen.pdf'],
		];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($items, $restrictions): array {
				$filters = ($config['filters'] ?? []);
				$schema  = ($filters['schema'] ?? ($config['schema'] ?? null));
				if ($schema === 'agenda-item' && ($filters['meeting'] ?? null) === 'meeting-1') {
					return array_map(fn (array $i) => $this->entity($i), $items);
				}

				if ($schema === 'confidentiality-restriction') {
					if ($restrictions === null) {
						throw new RuntimeException('database gone');
					}

					return array_map(fn (array $r) => $this->entity($r), $restrictions);
				}

				return [];
			}
		);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?object => isset($this->store[(string)$id]) === true ? $this->entity($this->store[(string)$id]) : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): object {
				$uuid = ($uuid ?? ('obj-' . (count($this->store) + 1)));
				$this->store[$uuid] = array_merge(['id' => $uuid, '_schema' => $schema], $object);
				return $this->entity($this->store[$uuid]);
			}
		);

		$fileService = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getFiles', 'publishFile', 'unpublishFile', 'formatFile'])
			->getMock();
		$fileService->method('getFiles')->willReturnCallback(
			function (ObjectEntity|string $object) use ($files): array {
				$nodes = [];
				foreach (($files[(string)$object] ?? []) as $id => $name) {
					$nodes[] = $this->node($id, $name);
				}

				return $nodes;
			}
		);
		$fileService->method('publishFile')->willReturnCallback(
			function (ObjectEntity|string $object, string|int $file) use ($files): File {
				$this->publishedFiles[] = $object . '/' . $file;
				return $this->node((int)$file, $files[(string)$object][(int)$file]);
			}
		);
		$fileService->method('formatFile')->willReturnCallback(
			static fn ($node): array => [
				'id' => $node->getId(),
				'title' => $node->getName(),
				'downloadUrl' => 'https://raad.example/s/' . $node->getName(),
				'type' => 'application/pdf',
				'labels' => [],
			]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === 'OCA\OpenRegister\Service\FileService') ? $fileService : $objects
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"body-1":{"catalog":"council-catalog"}}');
		$logger = $this->createMock(LoggerInterface::class);

		$payload = new PublicationPayloadService(
			$container,
			$logger,
			new PublicationConfigService($appConfig),
			new AgendaPapers($objects, $container, $logger),
		);

		$meeting = [
			'id' => 'meeting-1',
			'title' => 'Council meeting',
			'scheduledDate' => '2026-10-14T19:30:00+02:00',
			'governanceBody' => 'body-1',
			'bodyName' => 'Municipal council',
		];
		$eligibility = $this->createMock(PublicationEligibilityService::class);
		$eligibility->method('assertEligible')->willReturn($meeting);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		$publisher = $this->createMock(OpenCatalogiPublisher::class);
		$publisher->method('publish')->willReturnCallback(
			function (string $catalog, string $payloadId, array $payloadData): string {
				$this->catalogued[] = (string)json_encode([$catalog, $payloadId, $payloadData]);
				return 'catalog-publication-1';
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => '']);

		return new PublicationService(
			$logger,
			$appManager,
			$eligibility,
			$payload,
			new PublicationConfigService($appConfig),
			$publisher,
			$audit,
			$objects,
		);
	}//end service()

	/**
	 * The restrictions of the council agenda.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function restrictions(): array {
		return [
			['scope' => 'item', 'targetAgendaItem' => 'item-2', 'lifecycle' => 'imposed'],
			['scope' => 'item', 'targetAgendaItem' => 'item-3', 'lifecycle' => 'dissolved'],
			['scope' => 'item', 'targetAgendaItem' => 'item-4', 'lifecycle' => 'ratified'],
		];
	}//end restrictions()

	/**
	 * An item under an imposed or ratified restriction appears nowhere the
	 * public or the catalogue's search reads: not its title, not its papers.
	 *
	 * @return void
	 */
	public function testAConfidentialItemReachesNoPublicSurface(): void {
		$result = $this->service($this->restrictions())->publish('agenda', 'meeting-1', 'griffier');

		$surfaces = [
			'stored payload and record' => (string)json_encode($this->store),
			'catalogue metadata' => implode("\n", $this->catalogued),
			'published files' => implode("\n", $this->publishedFiles),
			'result' => (string)json_encode($result),
		];
		self::assertNotSame([], $this->catalogued, 'The agenda never reached the catalogue.');
		foreach ($surfaces as $surface => $text) {
			foreach (['Noordkade', 'Janssen', 'item-2', 'item-4'] as $secret) {
				self::assertStringNotContainsString($secret, $text, "'$secret' reached the $surface.");
			}
		}
	}//end testAConfidentialItemReachesNoPublicSurface()

	/**
	 * A dissolved restriction no longer keeps its item out: the item and the
	 * public item are both on the published agenda.
	 *
	 * @return void
	 */
	public function testADissolvedRestrictionNoLongerKeepsTheItemOut(): void {
		$this->service($this->restrictions())->publish('agenda', 'meeting-1', 'griffier');

		$payload = null;
		foreach ($this->store as $object) {
			if (isset($object['agendaItems']) === true) {
				$payload = $object;
			}
		}

		self::assertIsArray($payload);
		self::assertSame(['Housing plan', 'Budget amendment'], array_column($payload['agendaItems'], 'title'));
	}//end testADissolvedRestrictionNoLongerKeepsTheItemOut()

	/**
	 * When the restrictions cannot be read, nothing is published: no payload
	 * stored, nothing handed to the catalogue, no paper made public.
	 *
	 * @return void
	 */
	public function testRestrictionsThatCannotBeReadPublishNothing(): void {
		$service = $this->service(null);

		try {
			$service->publish('agenda', 'meeting-1', 'griffier');
			self::fail('An agenda whose confidentiality restrictions could not be read was published.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('confidential', $e->getMessage());
		}

		self::assertSame([], $this->store);
		self::assertSame([], $this->catalogued);
		self::assertSame([], $this->publishedFiles);
	}//end testRestrictionsThatCannotBeReadPublishNothing()
}//end class
