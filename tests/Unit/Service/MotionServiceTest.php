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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
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
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService.
     *
     * @var object&MockObject
     */
    private object&MockObject $objectService;

    /**
     * Mock NotificationService.
     *
     * @var object&MockObject
     */
    private object&MockObject $notificationService;

    /**
     * Mock Logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test doubles.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService       = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'saveObject', 'findAll'])
            ->getMock();

        $this->notificationService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['sendNotification'])
            ->getMock();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')
            ->willReturnCallback(function ($id) {
                if (str_contains($id, 'ObjectService')) {
                    return $this->objectService;
                }

                if (str_contains($id, 'NotificationService')) {
                    return $this->notificationService;
                }

                throw new \RuntimeException("Unmocked service: {$id}");
            });

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MotionService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that allowed lifecycle transition succeeds.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testTransitionLifecycleAllowed(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'motion-1', 'lifecycle' => 'submitted', 'status' => 'submitted']);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'motion',
                object: $this->callback(static function ($obj) {
                    return $obj['lifecycle'] === 'debating' && $obj['status'] === 'debating';
                })
            );

        $this->service->transitionLifecycle('motion-1', 'motion', 'debating', 'user1');

    }//end testTransitionLifecycleAllowed()

    /**
     * Test that a blocked lifecycle transition throws InvalidArgumentException.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testTransitionLifecycleBlocked(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'motion-1', 'lifecycle' => 'adopted', 'status' => 'adopted']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->service->transitionLifecycle('motion-1', 'motion', 'debating', 'user1');

    }//end testTransitionLifecycleBlocked()

    /**
     * Test that addCoSigner is idempotent and does not add duplicate names.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testAddCoSignerIdempotency(): void
    {
        $this->objectService->method('getObject')
            ->willReturn([
                'id'        => 'motion-1',
                'coSigners' => ['J. Existing'],
            ]);

        // saveObject must NOT be called because name already present.
        $this->objectService->expects($this->never())->method('saveObject');

        $this->service->addCoSigner('motion-1', 'J. Existing');

    }//end testAddCoSignerIdempotency()

    /**
     * Test that addCoSigner appends new names correctly.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testAddCoSignerAppendsNewName(): void
    {
        $this->objectService->method('getObject')
            ->willReturn([
                'id'        => 'motion-1',
                'coSigners' => ['J. Existing'],
            ]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'motion',
                object: $this->callback(static function ($obj) {
                    return in_array('M. New', $obj['coSigners'], true)
                        && count($obj['coSigners']) === 2;
                })
            );

        $this->service->addCoSigner('motion-1', 'M. New');

    }//end testAddCoSignerAppendsNewName()

    /**
     * Test detectConflicts with overlapping text triggers notification.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testDetectConflictsWithOverlap(): void
    {
        $longText = 'de raad besluit om een uitvoeringsplan te maken voor duurzame energie in de gemeente';

        $this->objectService->method('getObject')
            ->willReturn([
                'id'   => 'amendment-new',
                'text' => $longText,
            ]);

        $this->objectService->method('findAll')
            ->willReturn([
                [
                    'id'        => 'amendment-existing',
                    'lifecycle' => 'submitted',
                    'text'      => $longText . ' extra tekst',
                ],
            ]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'amendment',
                object: $this->callback(static function ($obj) {
                    $hasConflictNote = false;
                    foreach (($obj['notes'] ?? []) as $note) {
                        if (str_contains(($note['title'] ?? ''), 'Conflict')) {
                            $hasConflictNote = true;
                        }
                    }

                    return $hasConflictNote;
                })
            );

        $this->service->detectConflicts('motion-1', 'amendment-new');

    }//end testDetectConflictsWithOverlap()

    /**
     * Test detectConflicts with non-overlapping text does NOT trigger notification.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testDetectConflictsWithoutOverlap(): void
    {
        $this->objectService->method('getObject')
            ->willReturn([
                'id'   => 'amendment-new',
                'text' => 'korte tekst',
            ]);

        $this->objectService->method('findAll')
            ->willReturn([
                [
                    'id'        => 'amendment-existing',
                    'lifecycle' => 'submitted',
                    'text'      => 'totaal andere inhoud',
                ],
            ]);

        // No save should happen for non-conflicting amendments.
        $this->objectService->expects($this->never())->method('saveObject');

        $this->service->detectConflicts('motion-1', 'amendment-new');

    }//end testDetectConflictsWithoutOverlap()

    /**
     * Test applyAmendment appends amendment text to motion.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testApplyAmendmentUpdatesMotionText(): void
    {
        $this->objectService->method('getObject')
            ->willReturnMap([
                ['decidesk', 'motion', 'motion-1', null, ['id' => 'motion-1', 'text' => 'Original text']],
                ['decidesk', 'amendment', 'amendment-1', null, ['id' => 'amendment-1', 'title' => 'Amend 1', 'text' => 'Amendment text']],
            ]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'motion',
                object: $this->callback(static function ($obj) {
                    return str_contains($obj['text'], 'Amendment text')
                        && str_contains($obj['text'], 'Original text');
                })
            );

        $this->service->applyAmendment('motion-1', 'amendment-1');

    }//end testApplyAmendmentUpdatesMotionText()

}//end class
