<?php

/**
 * Unit tests for PublicationService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\OpenCatalogiPublisher;
use OCA\Decidesk\Service\PublicationConfigService;
use OCA\Decidesk\Service\PublicationEligibilityService;
use OCA\Decidesk\Service\PublicationPayloadService;
use OCA\Decidesk\Service\PublicationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests publish/withdraw/rectify flow + catalog-retraction failure branch.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class PublicationServiceTest extends TestCase
{

    /**
     * In-memory object store keyed by uuid.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $store = [];

    /**
     * Auto-increment counter for generated UUIDs.
     *
     * @var int
     */
    private int $seq = 0;

    /**
     * Build a fully-wired PublicationService over an in-memory ObjectService.
     *
     * @param bool                       $openCatalogi    Whether OpenCatalogi is "installed".
     * @param OpenCatalogiPublisher|null $catalogOverride Optional publisher double.
     *
     * @return PublicationService
     */
    private function makeService(bool $openCatalogi=false, ?OpenCatalogiPublisher $catalogOverride=null): PublicationService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?object {
                if (isset($this->store[(string) $id]) === false) {
                    return null;
                }

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($this->store[(string) $id]);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): object {
                if ($uuid === null) {
                    $this->seq++;
                    $uuid = 'obj-'.$this->seq;
                }

                $row = array_merge(['id' => $uuid], $object);
                $this->store[$uuid] = $row;

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getUuid')->willReturn($uuid);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn(json_encode(['body-1' => ['catalog' => 'cat-1', 'policy' => [], 'attendance' => 'counts']]));
        $configService = new PublicationConfigService($appConfig);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturnCallback(
            static function (string $appId) use ($openCatalogi): bool {
                return ($appId === 'opencatalogi') ? $openCatalogi : false;
            }
        );

        $eligibility = new PublicationEligibilityService($container, $logger);
        $payload     = new PublicationPayloadService($container, $logger, $configService);

        $catalog = $catalogOverride;
        if ($catalog === null) {
            $catalog = $this->createMock(OpenCatalogiPublisher::class);
            $catalog->method('publish')->willReturn('');
            $catalog->method('retract')->willReturn(true);
        }

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => '']);

        return new PublicationService($container, $logger, $appManager, $eligibility, $payload, $configService, $catalog, $audit);

    }//end makeService()

    /**
     * Reset the store before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store = [];
        $this->seq   = 0;

    }//end setUp()

    /**
     * Publishing an enacted decision creates a record and a payload.
     *
     * @return void
     */
    public function testPublishEnactedDecisionCreatesRecord(): void
    {
        $this->store['dec-1'] = ['id' => 'dec-1', 'title' => 'Begroting', 'lifecycle' => 'enacted', 'outcome' => 'adopted', 'governanceBody' => 'body-1', 'decisionType' => 'meeting-outcome'];

        $service = $this->makeService(openCatalogi: false);
        $result  = $service->publish('decision', 'dec-1', 'j.bakker');

        $this->assertSame('published', $result['record']['status']);
        $this->assertSame('Besluit', $result['record']['oriType']);
        $this->assertSame('j.bakker', $result['record']['publishedBy']);
        // OpenCatalogi absent => warning surfaced, never a silent success.
        $this->assertContains('opencatalogi-absent', $result['warnings']);
        // The decision source was stamped published (flow-owned write).
        $this->assertSame('public', $this->store['dec-1']['isPublished']);

    }//end testPublishEnactedDecisionCreatesRecord()

    /**
     * Withdraw requires a non-empty reason.
     *
     * @return void
     */
    public function testWithdrawRequiresReason(): void
    {
        $this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'decision', 'sourceObject' => 'dec-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => '', 'status' => 'published'];

        $service = $this->makeService();
        $this->expectException(\InvalidArgumentException::class);
        $service->withdraw('rec-1', 'j.bakker', '  ');

    }//end testWithdrawRequiresReason()

    /**
     * Withdraw records actor, reason, and timestamp and flips status.
     *
     * @return void
     */
    public function testWithdrawRecordsReasonAndResetsSource(): void
    {
        $this->store['dec-1'] = ['id' => 'dec-1', 'title' => 'x', 'lifecycle' => 'enacted', 'isPublished' => 'public', 'decisionType' => 'motion'];
        $this->store['pay-1'] = ['id' => 'pay-1', 'oriType' => 'Besluit'];
        $this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'decision', 'sourceObject' => 'dec-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => '', 'status' => 'published'];

        $service = $this->makeService();
        $result  = $service->withdraw('rec-1', 'j.bakker', 'Onjuiste tekst');

        $this->assertSame('withdrawn', $result['record']['status']);
        $this->assertSame('Onjuiste tekst', $result['record']['withdrawReason']);
        $this->assertSame('j.bakker', $result['record']['withdrawnBy']);
        $this->assertSame('internal', $this->store['dec-1']['isPublished']);

    }//end testWithdrawRecordsReasonAndResetsSource()

    /**
     * Catalog-retraction failure marks the record pending and surfaces a warning.
     *
     * @return void
     */
    public function testCatalogRetractionFailureSurfaced(): void
    {
        $this->store['dec-1'] = ['id' => 'dec-1', 'title' => 'x', 'lifecycle' => 'enacted', 'isPublished' => 'public', 'decisionType' => 'motion'];
        $this->store['pay-1'] = ['id' => 'pay-1', 'oriType' => 'Besluit'];
        $this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'decision', 'sourceObject' => 'dec-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => 'catpub-1', 'targetCatalog' => 'cat-1', 'status' => 'published'];

        $failing = $this->createMock(OpenCatalogiPublisher::class);
        $failing->method('publish')->willReturn('');
        $failing->method('retract')->willReturn(false);

        $service = $this->makeService(openCatalogi: true, catalogOverride: $failing);
        $result  = $service->withdraw('rec-1', 'j.bakker', 'fout');

        $this->assertSame('pending', $result['record']['catalogRetractionStatus']);
        $this->assertContains('catalog-retraction-failed', $result['warnings']);

    }//end testCatalogRetractionFailureSurfaced()

    /**
     * Rectify publishes a new version referencing the old one and withdraws it.
     *
     * @return void
     */
    public function testRectifyVersioning(): void
    {
        $this->store['min-1'] = ['id' => 'min-1', 'title' => 'Notulen', 'lifecycle' => 'approved', 'content' => 'corrected', 'governanceBody' => 'body-1'];
        $this->store['pay-1'] = ['id' => 'pay-1', 'oriType' => 'Verslag'];
        $this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'minutes', 'sourceObject' => 'min-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => '', 'targetCatalog' => 'cat-1', 'status' => 'published'];

        $service = $this->makeService(openCatalogi: false);
        $result  = $service->rectify('rec-1', 'j.bakker', 'Correctie');

        $this->assertSame(2, $result['record']['payloadVersion']);
        $this->assertSame(1, $result['record']['rectifiesVersion']);
        // The prior record was withdrawn in the same operation.
        $this->assertSame('withdrawn', $result['previous']['status']);

    }//end testRectifyVersioning()
}//end class
