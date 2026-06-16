<?php

/**
 * Unit tests for ParticipationPublicationService.
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
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the PII-free reaction digest, setting the RBAC published predicate
 * (publicatiedatum), and the OpenCatalogi-absent graceful degradation.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ParticipationPublicationServiceTest extends TestCase
{

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock app manager.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->container->method('get')->willReturn($this->objectService);
        $this->appManager = $this->createMock(IAppManager::class);

    }//end setUp()

    /**
     * Build the service with the configured OpenCatalogi presence.
     *
     * @param bool $openCatalogi Whether OpenCatalogi reports installed.
     *
     * @return ParticipationPublicationService
     */
    private function makeService(bool $openCatalogi): ParticipationPublicationService
    {
        $this->appManager->method('isInstalled')->willReturn($openCatalogi);
        return new ParticipationPublicationService(
            container: $this->container,
            logger: $this->createMock(LoggerInterface::class),
            appManager: $this->appManager,
            appConfig: $this->createMock(IAppConfig::class),
            budgetService: $this->createMock(BudgetVotingService::class),
        );

    }//end makeService()

    /**
     * Build an ObjectEntity mock serialising to the given array.
     *
     * @param array<string, mixed> $data Payload.
     *
     * @return ObjectEntity&MockObject
     */
    private function entity(array $data): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;

    }//end entity()

    /**
     * The reaction digest carries body+timestamp only — no submitterId / PII.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testReactionDigestIsPiiFree(): void
    {
        $reactions = [
            $this->entity(['body' => 'Idea one', 'submittedAt' => '2026-06-15T10:00:00+00:00', 'submitterId' => 'alice', 'moderationStatus' => 'approved']),
            $this->entity(['body' => 'Idea two', 'submittedAt' => '2026-06-15T11:00:00+00:00', 'submitterId' => 'anon-deadbeef', 'moderationStatus' => 'approved']),
        ];
        $this->objectService->method('findAll')->willReturn($reactions);

        $digest = $this->makeService(openCatalogi: false)->buildReactionDigest(consultationId: 'c1');
        self::assertCount(2, $digest);
        foreach ($digest as $entry) {
            self::assertArrayHasKey('body', $entry);
            self::assertArrayNotHasKey('submitterId', $entry);
            // No PII anywhere in the serialised entry.
            self::assertStringNotContainsString('alice', json_encode($entry));
            self::assertStringNotContainsString('anon-', json_encode($entry));
        }

    }//end testReactionDigestIsPiiFree()

    /**
     * Publishing consultation results sets publicatiedatum (the RBAC published
     * predicate), reports anonVisibilityVerified=true, and degrades with a
     * warning when OpenCatalogi is absent.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testPublishConsultationDegradesWithoutOpenCatalogi(): void
    {
        $this->objectService->method('find')->willReturn($this->entity(['id' => 'c1', 'title' => 'Visie', 'status' => 'closed']));
        $this->objectService->method('findAll')->willReturn([]);
        $captured = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (...$args) use (&$captured) {
                $captured = $args[0] ?? [];
                return $this->entity($captured);
            }
        );

        $result = $this->makeService(openCatalogi: false)->publishConsultationResults(consultationId: 'c1', staffResponse: 'Thanks');
        self::assertTrue($result['publishedPredicateSet']);
        // RBAC model: publicatiedatum <= $now makes the object anon-readable.
        self::assertTrue($result['anonVisibilityVerified']);
        self::assertFalse($result['openCatalogiInstalled']);
        self::assertFalse($result['openCatalogiRouted']);
        self::assertNotNull($result['warning']);
        // The RBAC published predicate (publicatiedatum) was set on the source
        // object as a normal field, in the past so the public-group rule matches.
        self::assertArrayHasKey('publicatiedatum', $captured);
        self::assertLessThanOrEqual(
            (new \DateTimeImmutable())->getTimestamp(),
            (new \DateTimeImmutable((string) $captured['publicatiedatum']))->getTimestamp()
        );
        self::assertArrayHasKey('depublicatiedatum', $captured);
        self::assertNull($captured['depublicatiedatum']);
        // No legacy @self.published predicate is written anymore.
        self::assertArrayNotHasKey('@self', $captured);
        // The summary stored on the object is PII-free (no submitter ids).
        self::assertStringNotContainsString('submitterId', (string) ($captured['resultsSummary'] ?? ''));

    }//end testPublishConsultationDegradesWithoutOpenCatalogi()

    /**
     * publishReaction refuses a non-approved reaction.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testPublishReactionRefusesNonApproved(): void
    {
        $this->objectService->method('find')->willReturn($this->entity(['id' => 'r1', 'moderationStatus' => 'pending']));
        $this->expectException(\RuntimeException::class);
        $this->makeService(openCatalogi: false)->publishReaction(reactionId: 'r1');

    }//end testPublishReactionRefusesNonApproved()

}//end class
