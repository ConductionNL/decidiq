<?php

/**
 * Golden runs of publish, withdraw and rectify.
 *
 * Records every object read and write, catalog call, audit entry and
 * subscriber event the publication service makes, in order, and the result
 * it returns, over a published-and-catalogued decision, a decision without a
 * catalog, a failing catalog, a refused source and an empty withdraw reason.
 * Timestamps are masked. Pinned before publish and rectify were split into
 * named steps.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude golden test of the publication flow, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaPapers;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class PublicationServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/publication-service-golden.json';

	private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

	/**
	 * In-memory objects keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Generated uuid counter.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Everything the service did, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * Every run, by case name.
	 *
	 * @var array<string, mixed>
	 */
	private array $runs = [];

	/**
	 * The whole publication flow over every case matches the golden.
	 *
	 * @return void
	 */
	public function testThePublicationFlowIsUnchanged(): void {
		$this->runCase('publish with a catalog', true, 'cat-ref-1', fn (PublicationService $s): array => $s->publish('decision', 'dec-1', 'j.bakker'));
		$this->runCase('publish without opencatalogi', false, 'cat-ref-1', fn (PublicationService $s): array => $s->publish('decision', 'dec-1', 'j.bakker'));
		$this->runCase('publish with a failing catalog', true, '', fn (PublicationService $s): array => $s->publish('decision', 'dec-1', 'j.bakker'));
		$this->runCase('publish a body without a catalog', true, 'cat-ref-1', fn (PublicationService $s): array => $s->publish('decision', 'dec-3', 'j.bakker'));
		$this->runCase('publish a refused source', true, 'cat-ref-1', fn (PublicationService $s): array => $s->publish('decision', 'dec-draft', 'j.bakker'));
		$this->runCase('withdraw with a catalog', true, 'cat-ref-1', fn (PublicationService $s): array => $s->withdraw('rec-1', 'j.bakker', 'Ingetrokken'));
		$this->runCase('withdraw with a failing retraction', true, 'cat-ref-1', fn (PublicationService $s): array => $s->withdraw('rec-1', 'j.bakker', 'Ingetrokken'), false);
		$this->runCase('withdraw without a reason', true, 'cat-ref-1', fn (PublicationService $s): array => $s->withdraw('rec-1', 'j.bakker', '  '));
		$this->runCase('rectify with a reason', true, 'cat-ref-2', fn (PublicationService $s): array => $s->rectify('rec-1', 'j.bakker', 'Typfout'));
		$this->runCase('rectify without a reason', false, 'cat-ref-2', fn (PublicationService $s): array => $s->rectify('rec-1', 'j.bakker', ''));
		$this->runCase('rectify a missing record', true, 'cat-ref-2', fn (PublicationService $s): array => $s->rectify('rec-missing', 'j.bakker', ''));

		$json = json_encode($this->runs, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('DECIDIQ_WRITE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testThePublicationFlowIsUnchanged()

	/**
	 * Run one case over a fresh store and keep its trace.
	 *
	 * @param string   $name      The case.
	 * @param bool     $catalogue Whether OpenCatalogi is installed.
	 * @param string   $reference What the catalog's publish answers.
	 * @param callable $call      The call under test.
	 * @param bool     $retracts  What the catalog's retract answers.
	 *
	 * @return void
	 */
	private function runCase(string $name, bool $catalogue, string $reference, callable $call, bool $retracts=true): void {
		$this->seed();
		$service = $this->service($catalogue, [$reference, $retracts]);
		try {
			$outcome = ['result' => $call($service)];
		} catch (Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$this->runs[$name] = $this->masked(['trace' => $this->trace, 'outcome' => $outcome, 'store' => $this->store]);
	}//end runCase()

	/**
	 * A fresh store: an enacted decision with a catalogued body, one in a body
	 * without a catalog, a draft, and a published record of the first.
	 *
	 * @return void
	 */
	private function seed(): void {
		$this->seq   = 0;
		$this->trace = [];
		$this->store = [
			'dec-1' => ['id' => 'dec-1', 'title' => 'Begroting', 'lifecycle' => 'enacted', 'outcome' => 'adopted', 'governanceBody' => 'body-1', 'decisionType' => 'meeting-outcome'],
			'dec-3' => ['id' => 'dec-3', 'title' => 'Zonder catalogus', 'lifecycle' => 'decided', 'outcome' => 'adopted', 'governanceBody' => ['body-9'], 'decisionType' => 'meeting-outcome'],
			'dec-draft' => ['id' => 'dec-draft', 'title' => 'Concept', 'lifecycle' => 'draft', 'governanceBody' => 'body-1', 'decisionType' => 'meeting-outcome'],
			'pay-1' => ['id' => 'pay-1', 'oriType' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00'],
			'rec-1' => [
				'id' => 'rec-1',
				'sourceType' => 'decision',
				'sourceObject' => 'dec-1',
				'sourceTitle' => 'Begroting (oud)',
				'governanceBody' => 'body-1',
				'payloadObject' => 'pay-1',
				'payloadVersion' => 2,
				'catalogPublication' => 'cat-ref-0',
				'targetCatalog' => 'cat-1',
				'status' => 'published',
				'publishedPapers' => [],
			],
		];
	}//end seed()

	/**
	 * Replace every ISO timestamp by a marker, recursively.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The masked value.
	 */
	private function masked(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->masked($item), $value);
		}

		if (is_string($value) === true && preg_match(self::TIMESTAMP, $value) === 1) {
			return '<now>';
		}

		return $value;
	}//end masked()

	/**
	 * The service over recording doubles.
	 *
	 * @param bool                       $catalogue Whether OpenCatalogi is installed.
	 * @param array{0: string, 1: bool}  $catalog   What publish and retract answer.
	 *
	 * @return PublicationService The service.
	 */
	private function service(bool $catalogue, array $catalog): PublicationService {
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?object {
				$this->trace[] = ['find' => (string)$id];
				if (isset($this->store[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($this->store[(string)$id]);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): object {
				$this->trace[] = ['save' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($uuid === null) {
					$this->seq++;
					$uuid = 'obj-' . $this->seq;
				}

				$row = array_merge(['id' => $uuid], $object);
				$this->store[$uuid] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(json_encode(['body-1' => ['catalog' => 'cat-1', 'policy' => [], 'attendance' => 'counts']]));
		$configService = new PublicationConfigService($appConfig);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(
			static fn (string $appId): bool => ($appId === 'opencatalogi') ? $catalogue : false
		);

		$publisher = $this->createMock(OpenCatalogiPublisher::class);
		$publisher->method('publish')->willReturnCallback(
			function (string $catalogId, string $payloadId, array $payload) use ($catalog): string {
				$this->trace[] = ['catalogPublish' => $catalogId, 'payload' => $payloadId, 'oriType' => ($payload['oriType'] ?? null)];
				return $catalog[0];
			}
		);
		$publisher->method('retract')->willReturnCallback(
			function (string $reference) use ($catalog): bool {
				$this->trace[] = ['catalogRetract' => $reference];
				return $catalog[1];
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload = []): array {
				$this->trace[] = ['audit' => $action, 'actor' => $actor, 'objects' => $objectUids, 'payload' => $payload];
				return ['success' => true, 'entry' => [], 'message' => ''];
			}
		);

		$recorder = $this->createMock(PublicationEventRecorder::class);
		$recorder->method('published')->willReturnCallback(
			function (string $sourceType, string $sourceId, array $source, ?string $bodyId): void {
				$this->trace[] = ['event' => $sourceType, 'source' => $sourceId, 'body' => $bodyId];
			}
		);

		$eligibility = new PublicationEligibilityService($logger, objectService: $objectService);
		$payload = new PublicationPayloadService($container, $logger, $configService, new AgendaPapers($objectService, $container, $logger));
		return new PublicationService($logger, $appManager, $eligibility, $payload, $configService, $publisher, $audit, $objectService, $recorder);
	}//end service()
}//end class
