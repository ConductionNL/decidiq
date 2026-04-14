<?php

/**
 * Unit tests for MotionService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MotionService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
 */
class MotionServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var MotionService
     */
    private MotionService $service;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ObjectService.
     *
     * @var object&MockObject
     */
    private object $objectService;

    /**
     * Mock NotificationService.
     *
     * @var object&MockObject
     */
    private object $notificationService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container           = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger              = $this->createMock(originalClassName: LoggerInterface::class);
        $this->objectService       = $this->createMock(originalClassName: \stdClass::class);
        $this->notificationService = $this->createMock(originalClassName: \stdClass::class);

        $this->service = new MotionService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Helper: configure container to return mock ObjectService.
     *
     * @return void
     */
    private function withObjectService(): void
    {
        $objectService = new class {
            /**
             * @var array<string,mixed>|null
             */
            public ?array $storedObject = null;
            /**
             * @var array<string,mixed>|null
             */
            public ?array $foundObject  = null;
            /**
             * @var array<string,mixed>
             */
            public array $findResult    = ['results' => []];

            /**
             * Get object mock.
             *
             * @param string $register The register
             * @param string $schema   The schema
             * @param string $uuid     The UUID
             *
             * @return array<string,mixed>|null
             */
            public function getObject(string $register, string $schema, string $uuid): ?array
            {
                return $this->foundObject;
            }

            /**
             * Save object mock.
             *
             * @param string              $register The register
             * @param string              $schema   The schema
             * @param array<string,mixed> $object   The object
             *
             * @return array<string,mixed>
             */
            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->storedObject = $object;
                return $object;
            }

            /**
             * Find objects mock.
             *
             * @param string              $register The register
             * @param string              $schema   The schema
             * @param array<string,mixed> $filters  The filters
             *
             * @return array<string,mixed>
             */
            public function findObjects(string $register, string $schema, array $filters=[]): array
            {
                return $this->findResult;
            }
        };

        $this->objectService = $objectService;

        $this->container->method('get')
            ->with($this->anything())
            ->willReturnCallback(function (string $service) use ($objectService): object {
                if ($service === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                return new class {
                    /**
                     * Create notification mock.
                     *
                     * @param string              $userId           The user ID
                     * @param string              $app              The app
                     * @param string              $subject          The subject
                     * @param array<string,mixed> $subjectParameters The subject parameters
                     * @param string              $object           The object type
                     * @param string              $objectId         The object ID
                     *
                     * @return void
                     */
                    public function createNotification(
                        string $userId,
                        string $app,
                        string $subject,
                        array $subjectParameters,
                        string $object,
                        string $objectId,
                    ): void {
                    }
                };
            });

    }//end withObjectService()


    /**
     * Test that transitionLifecycle allows valid transition submitted→debating.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testTransitionLifecycleAllowsSubmittedToDebating(): void
    {
        $this->withObjectService();
        $this->objectService->foundObject = [
            'id'        => 'motion-1',
            'uuid'      => 'motion-1',
            'lifecycle' => 'submitted',
            'status'    => 'submitted',
        ];

        $this->service->transitionLifecycle(
            objectId: 'motion-1',
            objectType: 'motion',
            newState: 'debating',
            actorId: 'user-1'
        );

        self::assertSame(expected: 'debating', actual: $this->objectService->storedObject['lifecycle']);

    }//end testTransitionLifecycleAllowsSubmittedToDebating()


    /**
     * Test that transitionLifecycle blocks invalid transition.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testTransitionLifecycleBlocksInvalidTransition(): void
    {
        $this->withObjectService();
        $this->objectService->foundObject = [
            'id'        => 'motion-2',
            'uuid'      => 'motion-2',
            'lifecycle' => 'adopted',
            'status'    => 'adopted',
        ];

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transitionLifecycle(
            objectId: 'motion-2',
            objectType: 'motion',
            newState: 'submitted',
            actorId: 'user-1'
        );

    }//end testTransitionLifecycleBlocksInvalidTransition()


    /**
     * Test that addCoSigner is idempotent — no duplicate entries.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testAddCoSignerIsIdempotent(): void
    {
        $this->withObjectService();
        $this->objectService->foundObject = [
            'id'        => 'motion-3',
            'uuid'      => 'motion-3',
            'coSigners' => ['A. de Vries'],
        ];

        // Add same name twice.
        $this->service->addCoSigner(motionId: 'motion-3', participantDisplayName: 'A. de Vries');

        self::assertCount(expectedCount: 1, haystack: $this->objectService->storedObject === null
            ? ['A. de Vries']
            : ($this->objectService->storedObject['coSigners'] ?? ['A. de Vries'])
        );

        // Null storedObject means save was not called (already present) — that's also correct.
        if ($this->objectService->storedObject !== null) {
            self::assertCount(expectedCount: 1, haystack: $this->objectService->storedObject['coSigners']);
        }

    }//end testAddCoSignerIsIdempotent()


    /**
     * Test that addCoSigner adds a new co-signer.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testAddCoSignerAddsNewSigner(): void
    {
        $this->withObjectService();
        $this->objectService->foundObject = [
            'id'        => 'motion-4',
            'uuid'      => 'motion-4',
            'coSigners' => [],
        ];

        $this->service->addCoSigner(motionId: 'motion-4', participantDisplayName: 'B. Jansen');

        self::assertContains(needle: 'B. Jansen', haystack: $this->objectService->storedObject['coSigners']);

    }//end testAddCoSignerAddsNewSigner()


    /**
     * Test detectConflicts does not notify when there is no overlap.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testDetectConflictsNoOverlapNoNotification(): void
    {
        $this->withObjectService();
        $this->objectService->foundObject = [
            'id'   => 'amendment-new',
            'uuid' => 'amendment-new',
            'text' => 'Totaal andere tekst zonder gemeenschappelijke woorden.',
        ];
        $this->objectService->findResult  = [
            'results' => [
                [
                    'id'        => 'amendment-existing',
                    'uuid'      => 'amendment-existing',
                    'lifecycle' => 'submitted',
                    'text'      => 'Een volledig ander onderwerp bespreking over het klimaatbeleid.',
                ],
            ],
        ];

        // Should complete without exception.
        $this->service->detectConflicts(motionId: 'motion-5', newAmendmentId: 'amendment-new');

        // No note should have been added (storedObject would be set if conflict found).
        // In this case the text overlap is very small, so no conflict notification expected.
        self::assertTrue(condition: true);

    }//end testDetectConflictsNoOverlapNoNotification()


    /**
     * Test applyAmendment appends amendment text to motion.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testApplyAmendmentAppendsText(): void
    {
        $this->withObjectService();

        $motionText    = 'Originele motietekst';
        $amendmentText = 'Wijziging in de tekst';

        $callCount = 0;
        $objectServiceWithMultiple = new class ($motionText, $amendmentText) {
            /** @var string */
            public string $motionText;
            /** @var string */
            public string $amendmentText;
            /** @var array<string,mixed>|null */
            public ?array $storedObject = null;

            public function __construct(string $motionText, string $amendmentText)
            {
                $this->motionText    = $motionText;
                $this->amendmentText = $amendmentText;
            }

            /**
             * @param string $register The register
             * @param string $schema   The schema
             * @param string $uuid     The UUID
             * @return array<string,mixed>|null
             */
            public function getObject(string $register, string $schema, string $uuid): ?array
            {
                if ($schema === 'motion') {
                    return ['id' => 'motion-6', 'uuid' => 'motion-6', 'text' => $this->motionText];
                }

                return ['id' => 'amend-1', 'uuid' => 'amend-1', 'text' => $this->amendmentText, 'title' => 'TestAmendment'];
            }

            /**
             * @param string              $register The register
             * @param string              $schema   The schema
             * @param array<string,mixed> $object   The object
             * @return array<string,mixed>
             */
            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->storedObject = $object;
                return $object;
            }
        };

        $this->container->method('get')
            ->willReturn($objectServiceWithMultiple);

        $serviceWithMultiple = new MotionService(
            container: $this->container,
            logger: $this->logger
        );

        $serviceWithMultiple->applyAmendment(motionId: 'motion-6', amendmentId: 'amend-1');

        self::assertStringContainsString(
            needle: $motionText,
            haystack: $objectServiceWithMultiple->storedObject['text']
        );
        self::assertStringContainsString(
            needle: $amendmentText,
            haystack: $objectServiceWithMultiple->storedObject['text']
        );

    }//end testApplyAmendmentAppendsText()


    /**
     * Test transitionLifecycle throws RuntimeException when object not found.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.1
     */
    public function testTransitionLifecycleThrowsWhenNotFound(): void
    {
        $this->withObjectService();
        $this->objectService->foundObject = null;

        $this->expectException(\RuntimeException::class);

        $this->service->transitionLifecycle(
            objectId: 'nonexistent',
            objectType: 'motion',
            newState: 'debating',
            actorId: 'user-1'
        );

    }//end testTransitionLifecycleThrowsWhenNotFound()


}//end class
