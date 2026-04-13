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
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MotionService.
 */
class MotionServiceTest extends TestCase
{

    /**
     * The service under test.
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
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock ObjectService (generic stdClass with added methods).
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'saveObject', 'getObjects'])
            ->getMock();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->container->method('get')
            ->willReturn($this->objectService);

        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);

        $this->service = new MotionService(
            container: $this->container,
            logger: $this->logger,
            appConfig: $this->appConfig,
        );

    }//end setUp()

    /**
     * Test that an allowed lifecycle transition is executed and saved.
     *
     * @return void
     */
    public function testTransitionLifecycleAllowedTransition(): void
    {
        $this->objectService->expects($this->once())
            ->method('getObject')
            ->with('motion', 'motion-1')
            ->willReturn([
                'id'        => 'motion-1',
                'lifecycle' => 'submitted',
                'status'    => 'submitted',
            ]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                'motion',
                $this->callback(function (array $object): bool {
                    return $object['lifecycle'] === 'debating'
                        && $object['status'] === 'debating';
                })
            )
            ->willReturnCallback(function (string $type, array $object): array {
                return $object;
            });

        $result = $this->service->transitionLifecycle(
            objectId: 'motion-1',
            objectType: 'motion',
            newState: 'debating',
            actorId: 'actor-1',
        );

        self::assertSame(expected: 'debating', actual: $result['lifecycle']);
        self::assertSame(expected: 'debating', actual: $result['status']);

    }//end testTransitionLifecycleAllowedTransition()

    /**
     * Test that a blocked lifecycle transition throws InvalidArgumentException.
     *
     * @return void
     */
    public function testTransitionLifecycleBlockedTransition(): void
    {
        $this->objectService->expects($this->once())
            ->method('getObject')
            ->with('motion', 'motion-2')
            ->willReturn([
                'id'        => 'motion-2',
                'lifecycle' => 'adopted',
                'status'    => 'adopted',
            ]);

        $this->objectService->expects($this->never())
            ->method('saveObject');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transitionLifecycle(
            objectId: 'motion-2',
            objectType: 'motion',
            newState: 'debating',
            actorId: 'actor-1',
        );

    }//end testTransitionLifecycleBlockedTransition()

    /**
     * Test that addCoSigner is idempotent for existing co-signers and appends new ones.
     *
     * @return void
     */
    public function testAddCoSignerIdempotent(): void
    {
        $motion = [
            'id'        => 'motion-3',
            'coSigners' => ['A'],
        ];

        // First call: 'A' already present, saveObject should NOT be called.
        $this->objectService->expects($this->exactly(2))
            ->method('getObject')
            ->with('motion', 'motion-3')
            ->willReturn($motion);

        $matcher = $this->exactly(1);
        $this->objectService->expects($matcher)
            ->method('saveObject')
            ->with(
                'motion',
                $this->callback(function (array $object): bool {
                    return $object['coSigners'] === ['A', 'B'];
                })
            )
            ->willReturnCallback(function (string $type, array $object): array {
                return $object;
            });

        // Idempotent: adding existing co-signer 'A' returns the motion unchanged.
        $resultA = $this->service->addCoSigner(
            motionId: 'motion-3',
            participantDisplayName: 'A',
        );

        self::assertSame(expected: ['A'], actual: $resultA['coSigners']);

        // Adding new co-signer 'B' triggers saveObject with ['A', 'B'].
        $resultB = $this->service->addCoSigner(
            motionId: 'motion-3',
            participantDisplayName: 'B',
        );

        self::assertSame(expected: ['A', 'B'], actual: $resultB['coSigners']);

    }//end testAddCoSignerIdempotent()

    /**
     * Test that detectConflicts returns conflicting amendment IDs when words overlap.
     *
     * @return void
     */
    public function testDetectConflictsWithOverlap(): void
    {
        $motion = [
            'id'         => 'motion-4',
            'amendments' => ['amend-existing', 'amend-new'],
        ];

        $existingAmendment = [
            'id'   => 'amend-existing',
            'text' => 'budget increase for education and infrastructure',
        ];

        $newAmendment = [
            'id'   => 'amend-new',
            'text' => 'budget increase for education and healthcare',
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(
                function (string $type, string $id) use ($motion, $existingAmendment, $newAmendment): array {
                    if ($type === 'motion' && $id === 'motion-4') {
                        return $motion;
                    }

                    if ($type === 'amendment' && $id === 'amend-new') {
                        return $newAmendment;
                    }

                    if ($type === 'amendment' && $id === 'amend-existing') {
                        return $existingAmendment;
                    }

                    return [];
                }
            );

        $conflicts = $this->service->detectConflicts(
            motionId: 'motion-4',
            newAmendmentId: 'amend-new',
        );

        self::assertContains(needle: 'amend-existing', haystack: $conflicts);

    }//end testDetectConflictsWithOverlap()

    /**
     * Test that detectConflicts returns an empty array when no overlap exists.
     *
     * @return void
     */
    public function testDetectConflictsNoOverlap(): void
    {
        $motion = [
            'id'         => 'motion-5',
            'amendments' => ['amend-alpha', 'amend-beta'],
        ];

        $alphaAmendment = [
            'id'   => 'amend-alpha',
            'text' => 'completely different topic about zoning regulations',
        ];

        $betaAmendment = [
            'id'   => 'amend-beta',
            'text' => 'budget increase for education and infrastructure',
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(
                function (string $type, string $id) use ($motion, $alphaAmendment, $betaAmendment): array {
                    if ($type === 'motion' && $id === 'motion-5') {
                        return $motion;
                    }

                    if ($type === 'amendment' && $id === 'amend-beta') {
                        return $betaAmendment;
                    }

                    if ($type === 'amendment' && $id === 'amend-alpha') {
                        return $alphaAmendment;
                    }

                    return [];
                }
            );

        $conflicts = $this->service->detectConflicts(
            motionId: 'motion-5',
            newAmendmentId: 'amend-beta',
        );

        self::assertEmpty(actual: $conflicts);

    }//end testDetectConflictsNoOverlap()

    /**
     * Test that applyAmendment appends the amendment text to the motion text.
     *
     * @return void
     */
    public function testApplyAmendmentUpdatesText(): void
    {
        $motion = [
            'id'   => 'motion-6',
            'text' => 'Original motion text',
        ];

        $amendment = [
            'id'    => 'amend-1',
            'title' => 'Budget Adjustment',
            'text'  => 'Add 10k to education budget.',
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(
                function (string $type, string $id) use ($motion, $amendment): array {
                    if ($type === 'motion' && $id === 'motion-6') {
                        return $motion;
                    }

                    if ($type === 'amendment' && $id === 'amend-1') {
                        return $amendment;
                    }

                    return [];
                }
            );

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                'motion',
                $this->callback(function (array $object): bool {
                    return str_contains($object['text'], 'Original motion text')
                        && str_contains($object['text'], '[Amendement: Budget Adjustment]')
                        && str_contains($object['text'], 'Add 10k to education budget.');
                })
            )
            ->willReturnCallback(function (string $type, array $object): array {
                return $object;
            });

        $result = $this->service->applyAmendment(
            motionId: 'motion-6',
            amendmentId: 'amend-1',
        );

        self::assertStringContainsString(
            needle: 'Original motion text',
            haystack: $result['text'],
        );
        self::assertStringContainsString(
            needle: 'Add 10k to education budget.',
            haystack: $result['text'],
        );

    }//end testApplyAmendmentUpdatesText()

}//end class
