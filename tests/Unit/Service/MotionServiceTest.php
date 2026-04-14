<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MotionService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
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
     * Mock logger.
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container     = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject', 'saveObject', 'findAll'])
            ->getMock();

        $this->container->method('get')
            ->willReturnCallback(function ($id) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objectService;
                }

                if ($id === 'OCA\OpenRegister\Service\NotificationService') {
                    $notificationService = $this->getMockBuilder(\stdClass::class)
                        ->addMethods(['sendNotification'])
                        ->getMock();
                    return $notificationService;
                }

                return null;
            });

        $this->service = new MotionService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that transitionLifecycle allows a valid motion transition.
     *
     * @return void
     */
    public function testTransitionLifecycleAllowsValidMotionTransition(): void
    {
        $motion = [
            'id'        => 'motion-1',
            'title'     => 'Test Motion',
            'lifecycle' => 'submitted',
            'status'    => 'submitted',
        ];

        $this->objectService->method('findObject')->willReturn($motion);
        $this->objectService->expects($this->once())->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'motion',
                object: $this->callback(static fn($obj) => $obj['lifecycle'] === 'debating')
            );

        $this->service->transitionLifecycle('motion-1', 'motion', 'debating', 'user-1');

        // No exception thrown = assertion passed.
        $this->assertTrue(condition: true);

    }//end testTransitionLifecycleAllowsValidMotionTransition()

    /**
     * Test that transitionLifecycle blocks an invalid motion transition.
     *
     * @return void
     */
    public function testTransitionLifecycleBlocksInvalidMotionTransition(): void
    {
        $motion = [
            'id'        => 'motion-1',
            'lifecycle' => 'submitted',
            'status'    => 'submitted',
        ];

        $this->objectService->method('findObject')->willReturn($motion);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->service->transitionLifecycle('motion-1', 'motion', 'adopted', 'user-1');

    }//end testTransitionLifecycleBlocksInvalidMotionTransition()

    /**
     * Test that transitionLifecycle allows valid amendment transitions.
     *
     * @return void
     */
    public function testTransitionLifecycleAllowsValidAmendmentTransition(): void
    {
        $amendment = [
            'id'        => 'amendment-1',
            'lifecycle' => 'submitted',
        ];

        $this->objectService->method('findObject')->willReturn($amendment);
        $this->objectService->expects($this->once())->method('saveObject');

        $this->service->transitionLifecycle('amendment-1', 'amendment', 'debating', 'user-1');

        $this->assertTrue(condition: true);

    }//end testTransitionLifecycleAllowsValidAmendmentTransition()

    /**
     * Test that addCoSigner appends a new co-signer name to the motion.
     *
     * @return void
     */
    public function testAddCoSignerAppendsNewCoSigner(): void
    {
        $motion = [
            'id'        => 'motion-1',
            'coSigners' => ['Alice'],
        ];

        $this->objectService->method('findObject')->willReturn($motion);
        $this->objectService->expects($this->once())->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'motion',
                object: $this->callback(
                    static fn($obj) => in_array('Bob', $obj['coSigners'], true) === true
                        && in_array('Alice', $obj['coSigners'], true) === true
                )
            );

        $this->service->addCoSigner('motion-1', 'Bob');

    }//end testAddCoSignerAppendsNewCoSigner()

    /**
     * Test that addCoSigner is idempotent — no duplicate added.
     *
     * @return void
     */
    public function testAddCoSignerIsIdempotent(): void
    {
        $motion = [
            'id'        => 'motion-1',
            'coSigners' => ['Alice'],
        ];

        $this->objectService->method('findObject')->willReturn($motion);
        // saveObject must NOT be called when the name is already present.
        $this->objectService->expects($this->never())->method('saveObject');

        $this->service->addCoSigner('motion-1', 'Alice');

    }//end testAddCoSignerIsIdempotent()

    /**
     * Test detectConflicts does not notify when amendments have no text overlap.
     *
     * @return void
     */
    public function testDetectConflictsNoConflictWhenNoOverlap(): void
    {
        $newAmendment = [
            'id'        => 'amd-new',
            'title'     => 'New Amendment',
            'text'      => 'Replace section five with new wording',
            'lifecycle' => 'submitted',
        ];

        $existing = [
            [
                'id'        => 'amd-old',
                'title'     => 'Old Amendment',
                'text'      => 'Modify the budget line for parks',
                'lifecycle' => 'submitted',
            ],
        ];

        $this->objectService->method('findObject')
            ->with(register: 'decidesk', schema: 'amendment', id: 'amd-new')
            ->willReturn($newAmendment);

        $this->objectService->method('findAll')
            ->willReturn(['results' => $existing]);

        // saveObject should NOT be called (no conflict note added).
        $this->objectService->expects($this->never())->method('saveObject');

        $this->service->detectConflicts('motion-1', 'amd-new');

    }//end testDetectConflictsNoConflictWhenNoOverlap()

    /**
     * Test detectConflicts writes a conflict note when overlap is detected.
     *
     * @return void
     */
    public function testDetectConflictsWritesConflictNoteOnOverlap(): void
    {
        $newAmendment = [
            'id'        => 'amd-new',
            'title'     => 'New Amendment',
            'text'      => 'Replace section uitvoeringsplan with new deadline',
            'lifecycle' => 'submitted',
            'notes'     => [],
        ];

        $existing = [
            [
                'id'        => 'amd-old',
                'title'     => 'Existing Amendment',
                'text'      => 'Adjust the uitvoeringsplan deadline to July',
                'lifecycle' => 'submitted',
            ],
        ];

        $this->objectService->method('findObject')
            ->with(register: 'decidesk', schema: 'amendment', id: 'amd-new')
            ->willReturn($newAmendment);

        $this->objectService->method('findAll')
            ->willReturn(['results' => $existing]);

        $this->objectService->expects($this->once())->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'amendment',
                object: $this->callback(
                    static fn($obj) => count(array_filter(
                        $obj['notes'],
                        static fn($n) => str_starts_with($n['title'], 'Conflict:')
                    )) > 0
                )
            );

        $this->service->detectConflicts('motion-1', 'amd-new');

    }//end testDetectConflictsWritesConflictNoteOnOverlap()

    /**
     * Test applyAmendment appends amendment text to motion text.
     *
     * @return void
     */
    public function testApplyAmendmentUpdatesMotionText(): void
    {
        $motion = [
            'id'   => 'motion-1',
            'text' => 'Original motion text.',
        ];

        $amendment = [
            'id'    => 'amendment-1',
            'title' => 'Amendment One',
            'text'  => 'Replaced text after amendment.',
        ];

        $this->objectService->method('findObject')
            ->willReturnCallback(
                static function ($register, $schema, $id) use ($motion, $amendment) {
                    if ($id === 'motion-1') {
                        return $motion;
                    }

                    if ($id === 'amendment-1') {
                        return $amendment;
                    }

                    return null;
                }
            );

        $this->objectService->expects($this->once())->method('saveObject')
            ->with(
                register: 'decidesk',
                schema: 'motion',
                object: $this->callback(
                    static fn($obj) => str_contains($obj['text'], 'Replaced text after amendment.')
                )
            );

        $this->service->applyAmendment('motion-1', 'amendment-1');

    }//end testApplyAmendmentUpdatesMotionText()

}//end class
