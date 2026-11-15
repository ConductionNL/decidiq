<?php

/**
 * Edge-case unit tests for ParticipationPublicationService: budget results,
 * OpenCatalogi routing, reaction publication and the not-found refusals.
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
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\BudgetVotingService;
use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\Decidiq\Service\SavedObjectNormaliser;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The paths ParticipationPublicationServiceTest leaves alone: OpenCatalogi
 * present (configured or not), budget results, reactions and refusals.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
#[CoversClass(ParticipationPublicationService::class)]
#[UsesClass(ObjectRelationFilter::class)]
#[UsesClass(SavedObjectNormaliser::class)]
class ParticipationPublicationServiceEdgesTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock budget service.
	 *
	 * @var BudgetVotingService&MockObject
	 */
	private BudgetVotingService&MockObject $budgetService;

	/**
	 * Every saveObject() call as [register, schema, object].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->budgetService = $this->createMock(BudgetVotingService::class);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return ParticipationPublicationService
	 */
	private function makeService(): ParticipationPublicationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				if ($id === ObjectRelationFilter::class) {
					return new ObjectRelationFilter();
				}

				throw new RuntimeException("Unexpected container::get('{$id}')");
			}
		);

		return new ParticipationPublicationService(
			container: $container,
			logger: $this->logger,
			appManager: $this->appManager,
			appConfig: $this->appConfig,
			budgetService: $this->budgetService,
			objectService: $this->objectService,
		);

	}//end makeService()

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;

	}//end entity()

	/**
	 * Record every saveObject() call; optionally fail the catalog write.
	 *
	 * @param bool $catalogFails Whether the OpenCatalogi publication write throws.
	 *
	 * @return void
	 */
	private function recordSaves(bool $catalogFails = false): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			SaveCallShape::legacy(function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null) use ($catalogFails): ObjectEntity {
				if ($catalogFails === true && $register === 'opencatalogi') {
					throw new RuntimeException('catalog schema missing');
				}

				$this->saves[] = [(string)$register, (string)$schema, $object];
				return $this->entity(array_merge($object, ['@self' => ['saved' => true]]));
			}));

	}//end recordSaves()

	/**
	 * Configure the per-body and default catalog targets.
	 *
	 * @param array<string, string> $values Config key => value; missing keys are ''.
	 *
	 * @return void
	 */
	private function catalogConfig(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				if ($app !== 'decidiq') {
					return $default;
				}

				return ($values[$key] ?? $default);
			}
		);

	}//end catalogConfig()

	/**
	 * A budget round's summary aggregates the allocation, marks the round, and routes
	 * to the governance body's own catalog.
	 *
	 * @return void
	 */
	public function testPublishBudgetResultsRoutesToTheBodysCatalog(): void {
		$this->objectService->expects($this->once())->method('find')
			->with('pb-1', [], false, 'decidiq', 'participatory-budget')
			->willReturn(
				$this->entity(
					[
						'id' => 'pb-1',
						'name' => 'Wijkbudget Noord',
						'description' => 'Verdeel 50k',
						'totalAmount' => '50000',
						'relations' => [
							'not-a-relation',
							['schema' => 'meeting', 'id' => 'm-1'],
							['schema' => 'governance-body', 'id' => ''],
							['schema' => 'governance-body', 'id' => 'gb-noord'],
						],
					]
				)
			);
		$proposals = [
			['title' => 'Speeltuin', 'votesFor' => 10, 'votesAgainst' => 2],
			['title' => 'Bankjes', 'votesFor' => '3'],
		];
		$this->budgetService->expects($this->once())->method('calculateAllocation')->with('pb-1')
			->willReturn(['allocatedAmount' => 42000, 'proposals' => $proposals]);
		$this->appManager->expects($this->once())->method('isEnabledForAnyone')->with('opencatalogi')->willReturn(true);
		$this->catalogConfig(['participation_catalog_gb-noord' => 'cat-noord', 'participation_catalog' => 'cat-default']);
		$this->recordSaves();
		$this->logger->expects($this->never())->method('warning');

		$result = $this->makeService()->publishBudgetResults(budgetId: 'pb-1');

		self::assertTrue($result['publishedPredicateSet']);
		self::assertTrue($result['openCatalogiInstalled']);
		self::assertTrue($result['openCatalogiRouted']);
		self::assertNull($result['warning']);
		self::assertSame('budget-results', $result['summary']['summaryType']);
		self::assertSame('Wijkbudget Noord', $result['summary']['title']);
		self::assertSame(50000.0, $result['summary']['totalAmount']);
		self::assertSame(42000.0, $result['summary']['allocatedAmount']);
		self::assertSame(15, $result['summary']['participationCount']);
		self::assertSame($proposals, $result['summary']['proposals']);

		self::assertCount(2, $this->saves);
		[$register, $schema, $round] = $this->saves[0];
		self::assertSame(['decidiq', 'participatory-budget'], [$register, $schema]);
		self::assertTrue($round['resultsPublished']);
		self::assertSame(15, json_decode($round['resultsSummary'], true)['participationCount']);
		// The normalised saved object is what the summary carries back.
		self::assertSame(['saved' => true], $result['summary']['sourceObject']['@self']);

		[$register, $schema, $publication] = $this->saves[1];
		self::assertSame(['opencatalogi', 'publication'], [$register, $schema]);
		self::assertSame('Wijkbudget Noord', $publication['title']);
		self::assertSame('Verdeel 50k', $publication['summary']);
		self::assertSame('cat-noord', $publication['catalog']);
		self::assertSame('pb-1', $publication['sourceId']);
		self::assertArrayHasKey('publicationDate', $publication);

	}//end testPublishBudgetResultsRoutesToTheBodysCatalog()

	/**
	 * A budget round without name or allocation falls back to the defaults and the
	 * instance-wide catalog when the body has none of its own.
	 *
	 * @return void
	 */
	public function testPublishBudgetResultsFallsBackToTheDefaultCatalog(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'pb-2', 'governanceBody' => 'gb-zuid']));
		$this->budgetService->method('calculateAllocation')->willReturn([]);
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$keys = [];
		$this->appConfig->expects($this->exactly(2))->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$keys): string {
				$keys[] = $key;
				if ($key === 'participation_catalog') {
					return 'cat-default';
				}

				return '';
			}
		);
		$this->recordSaves();

		$result = $this->makeService()->publishBudgetResults(budgetId: 'pb-2');

		self::assertSame(['participation_catalog_gb-zuid', 'participation_catalog'], $keys);
		self::assertTrue($result['openCatalogiRouted']);
		self::assertSame('Budget results', $result['summary']['title']);
		self::assertSame(0, $result['summary']['participationCount']);
		self::assertSame([], $result['summary']['proposals']);
		self::assertSame('cat-default', $this->saves[1][2]['catalog']);
		self::assertSame('Budget results', $this->saves[1][2]['title']);

	}//end testPublishBudgetResultsFallsBackToTheDefaultCatalog()

	/**
	 * OpenCatalogi installed but no catalog configured: published, not routed, and told why.
	 *
	 * @return void
	 */
	public function testInstalledOpenCatalogiWithoutATargetCatalogWarns(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'pb-3']));
		$this->budgetService->method('calculateAllocation')->willReturn([]);
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$keys = [];
		$this->appConfig->expects($this->exactly(2))->method('getValueString')->willReturnCallback(
			static function (string $app, string $key) use (&$keys): string {
				$keys[] = $key;
				return '';
			}
		);
		$this->recordSaves();

		$result = $this->makeService()->publishBudgetResults(budgetId: 'pb-3');

		// No body: the per-body key IS the default key, looked up twice.
		self::assertSame(['participation_catalog', 'participation_catalog'], $keys);
		self::assertTrue($result['publishedPredicateSet']);
		self::assertTrue($result['openCatalogiInstalled']);
		self::assertFalse($result['openCatalogiRouted']);
		self::assertSame(
			'OpenCatalogi is installed but no target catalog is configured for this governance body; '
			. 'the summary was not routed to a catalog.',
			$result['warning']
		);
		self::assertCount(1, $this->saves);

	}//end testInstalledOpenCatalogiWithoutATargetCatalogWarns()

	/**
	 * A failing catalog write is logged and reported as not routed, the predicate still set.
	 *
	 * @return void
	 */
	public function testAFailingCatalogWriteIsLoggedAndNotRouted(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'pb-4']));
		$this->budgetService->method('calculateAllocation')->willReturn([]);
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$this->catalogConfig(['participation_catalog' => 'cat-default']);
		$this->recordSaves(catalogFails: true);
		$this->logger->expects($this->once())->method('warning')
			->with('Decidiq participation: OpenCatalogi routing failed', ['error' => 'catalog schema missing']);

		$result = $this->makeService()->publishBudgetResults(budgetId: 'pb-4');

		self::assertTrue($result['publishedPredicateSet']);
		self::assertFalse($result['openCatalogiRouted']);
		self::assertStringStartsWith('OpenCatalogi is installed but no target catalog', (string)$result['warning']);

	}//end testAFailingCatalogWriteIsLoggedAndNotRouted()

	/**
	 * A failing presence check counts as "not installed" and is logged at debug level.
	 *
	 * @return void
	 */
	public function testAFailingPresenceCheckCountsAsNotInstalled(): void {
		$this->appManager->method('isEnabledForAnyone')->willThrowException(new RuntimeException('app store down'));
		$this->logger->expects($this->once())->method('debug')
			->with('Decidiq participation: OpenCatalogi presence check failed', ['error' => 'app store down']);

		self::assertFalse($this->makeService()->isOpenCatalogiInstalled());

	}//end testAFailingPresenceCheckCountsAsNotInstalled()

	/**
	 * A source that disappears between the first read and the publish is published from an empty object.
	 *
	 * @return void
	 */
	public function testConsultationVanishedBeforePublishIsSavedFromAnEmptySource(): void {
		$this->objectService->expects($this->exactly(2))->method('find')
			->with('c-gone', [], false, 'decidiq', 'public-consultation')
			->willReturnOnConsecutiveCalls($this->entity(['id' => 'c-gone', 'title' => 'Oud']), null);
		$this->objectService->method('findAll')->willReturn([]);
		$this->appManager->method('isEnabledForAnyone')->willReturn(false);
		$this->recordSaves();

		$result = $this->makeService()->publishConsultationResults(consultationId: 'c-gone');

		self::assertTrue($result['publishedPredicateSet']);
		self::assertSame(['resultsSummary', 'publicationDate'], array_keys($this->saves[0][2]));
		self::assertSame('Oud', json_decode($this->saves[0][2]['resultsSummary'], true)['title']);

	}//end testConsultationVanishedBeforePublishIsSavedFromAnEmptySource()

	/**
	 * Reactions that are not approved never enter the public digest, even when the store returns them.
	 *
	 * @return void
	 */
	public function testDigestSkipsReactionsThatAreNotApproved(): void {
		$relation = [['schema' => 'public-consultation', 'id' => 'c1']];
		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['_relations.relations' => 'c1', 'moderationStatus' => 'approved']])
			->willReturn(
				[
					$this->entity(['body' => 'Spam', 'moderationStatus' => 'rejected', 'relations' => $relation]),
					$this->entity(['body' => 'Pending', 'relations' => $relation]),
					$this->entity(['body' => 'Ok', 'submittedAt' => '2026-06-01', 'moderationStatus' => 'approved', 'relations' => $relation]),
				]
			);

		self::assertSame(
			[['body' => 'Ok', 'submittedAt' => '2026-06-01']],
			$this->makeService()->buildReactionDigest(consultationId: 'c1')
		);

	}//end testDigestSkipsReactionsThatAreNotApproved()

	/**
	 * Publishing one approved reaction sets the predicate on it and returns the saved object.
	 *
	 * @return void
	 */
	public function testPublishReactionSetsThePredicateOnAnApprovedReaction(): void {
		$this->objectService->expects($this->once())->method('find')
			->with('r-1', [], false, 'decidiq', 'consultation-reaction')
			->willReturn($this->entity(['id' => 'r-1', 'body' => 'Goed plan', 'moderationStatus' => 'approved']));
		$this->recordSaves();

		$before = time();
		$result = $this->makeService()->publishReaction(reactionId: 'r-1');

		self::assertCount(1, $this->saves);
		[$register, $schema, $saved] = $this->saves[0];
		self::assertSame(['decidiq', 'consultation-reaction'], [$register, $schema]);
		self::assertArrayHasKey('depublicationDate', $saved);
		self::assertNull($saved['depublicationDate']);
		self::assertGreaterThanOrEqual($before, (new \DateTimeImmutable($saved['publicationDate']))->getTimestamp());
		self::assertSame(array_merge($saved, ['@self' => ['saved' => true]]), $result);

	}//end testPublishReactionSetsThePredicateOnAnApprovedReaction()

	/**
	 * Each publish entry point refuses a missing source with a message naming it.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function missingSourceProvider(): array {
		return [
			'consultation' => ['publishConsultationResults', 'PublicConsultation x-1 not found'],
			'budget' => ['publishBudgetResults', 'ParticipatoryBudget x-1 not found'],
			'evaluation' => ['publishEvaluationResults', 'BoardEvaluation x-1 not found'],
			'reaction' => ['publishReaction', 'ConsultationReaction x-1 not found'],
		];

	}//end missingSourceProvider()

	/**
	 * A missing source is refused before anything is written.
	 *
	 * @param string $method  The publish method.
	 * @param string $message The expected refusal.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('missingSourceProvider')]
	public function testAMissingSourceIsRefused(string $method, string $message): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$this->makeService()->{$method}('x-1');

	}//end testAMissingSourceIsRefused()

	/**
	 * An evaluation that is still open may not be published.
	 *
	 * @return void
	 */
	public function testAnOpenEvaluationIsNotPublished(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'be-1', 'lifecycle' => 'open']));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Only a closed evaluation may be published');

		$this->makeService()->publishEvaluationResults(evaluationId: 'be-1');

	}//end testAnOpenEvaluationIsNotPublished()
}//end class
