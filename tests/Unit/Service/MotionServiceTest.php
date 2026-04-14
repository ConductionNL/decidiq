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
 * Tests for MotionService.
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setRegister', 'setSchema', 'find', 'findAll', 'saveObject'])
            ->getMock();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new MotionService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Build a mock ObjectEntity with getObject() and getUuid() methods.
     *
     * @param array<string,mixed> $data Object data
     * @param string              $uuid Object UUID
     *
     * @return object
     */
    private function mockObjectEntity(array $data, string $uuid='test-uuid'): object
    {
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'getUuid'])
            ->getMock();
        $entity->method('getObject')->willReturn($data);
        $entity->method('getUuid')->willReturn($uuid);
        return $entity;

    }//end mockObjectEntity()

    /**
     * Test that transitionLifecycle succeeds for an allowed transition.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testTransitionLifecycleAllowedTransition(): void
    {
        $motionEntity = $this->mockObjectEntity(['lifecycle' => 'submitted', 'title' => 'Test Motie'], 'motion-uuid');

        $this->objectService->method('find')->willReturn($motionEntity);
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(fn($obj) => ($obj['lifecycle'] ?? '') === 'debating'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($motionEntity);

        $this->service->transitionLifecycle('motion-uuid', 'motion', 'debating', 'user1');

    }//end testTransitionLifecycleAllowedTransition()

    /**
     * Test that transitionLifecycle throws when transition is not allowed.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testTransitionLifecycleBlocksInvalidTransition(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $motionEntity = $this->mockObjectEntity(['lifecycle' => 'submitted', 'title' => 'Test Motie'], 'motion-uuid');
        $this->objectService->method('find')->willReturn($motionEntity);

        // adopted → debating is not allowed.
        $this->service->transitionLifecycle('motion-uuid', 'motion', 'adopted', 'user1');

        // Transition from submitted → adopted is also not allowed directly.
    }//end testTransitionLifecycleBlocksInvalidTransition()

    /**
     * Test that addCoSigner appends a new co-signer idempotently.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testAddCoSignerIdempotent(): void
    {
        $motionEntity = $this->mockObjectEntity([
            'title'     => 'Test Motie',
            'coSigners' => ['A. Pietersen'],
        ], 'motion-uuid');

        $this->objectService->method('find')->willReturn($motionEntity);

        // First call: add a new co-signer — saveObject MUST be called.
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(fn($obj) => in_array('M. de Vries', $obj['coSigners'] ?? [], true)),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($motionEntity);

        $this->service->addCoSigner('motion-uuid', 'M. de Vries');

    }//end testAddCoSignerIdempotent()

    /**
     * Test that addCoSigner does NOT duplicate an existing co-signer.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testAddCoSignerDoesNotDuplicate(): void
    {
        $motionEntity = $this->mockObjectEntity([
            'title'     => 'Test Motie',
            'coSigners' => ['A. Pietersen'],
        ], 'motion-uuid');

        $this->objectService->method('find')->willReturn($motionEntity);

        // saveObject must NOT be called because name already exists.
        $this->objectService->expects($this->never())->method('saveObject');

        $this->service->addCoSigner('motion-uuid', 'A. Pietersen');

    }//end testAddCoSignerDoesNotDuplicate()

    /**
     * Test that detectConflicts returns without saving when no overlap exists.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testDetectConflictsNoOverlap(): void
    {
        $newAmendment = $this->mockObjectEntity([
            'text'      => 'Completely different text about transport',
            'lifecycle' => 'submitted',
        ], 'new-amendment-uuid');

        $existingAmendment = $this->mockObjectEntity([
            'id'        => 'other-amendment-uuid',
            'text'      => 'Zonnepanelen beleid voor scholen',
            'lifecycle' => 'submitted',
            'relations' => [['schema' => 'motion', 'id' => 'motion-uuid']],
        ], 'other-amendment-uuid');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with('new-amendment-uuid')
            ->willReturn($newAmendment);

        $this->objectService->method('findAll')
            ->willReturn([$existingAmendment]);

        // No conflict note should be saved.
        $this->objectService->expects($this->never())->method('saveObject');

        $this->service->detectConflicts('motion-uuid', 'new-amendment-uuid');

    }//end testDetectConflictsNoOverlap()

    /**
     * Test that detectConflicts saves a conflict note when overlap is detected.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testDetectConflictsWithOverlap(): void
    {
        $overlappingText = 'De raad besluit uitvoeringsplan duurzame energie begroting jeugdzorg aanpassen vastgesteld';

        $newAmendment = $this->mockObjectEntity([
            'text'      => $overlappingText,
            'lifecycle' => 'submitted',
        ], 'new-amendment-uuid');

        $existingAmendment = $this->mockObjectEntity([
            'id'        => 'other-amendment-uuid',
            'text'      => $overlappingText,
            'lifecycle' => 'submitted',
            'relations' => [['schema' => 'motion', 'id' => 'motion-uuid']],
        ], 'other-amendment-uuid');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with('new-amendment-uuid')
            ->willReturn($newAmendment);

        $this->objectService->method('findAll')
            ->willReturn([$existingAmendment]);

        // Conflict note MUST be saved.
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(fn($obj) => !empty(array_filter(
                    $obj['notes'] ?? [],
                    fn($n) => str_starts_with($n['title'] ?? '', 'Conflict:')
                ))),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($newAmendment);

        $this->service->detectConflicts('motion-uuid', 'new-amendment-uuid');

    }//end testDetectConflictsWithOverlap()

    /**
     * Test that applyAmendment appends amendment text to the motion text.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testApplyAmendmentUpdatesMotionText(): void
    {
        $amendmentEntity = $this->mockObjectEntity([
            'title' => 'Amendement A',
            'text'  => 'Vervangende tekst voor artikel 2.',
        ], 'amendment-uuid');

        $motionEntity = $this->mockObjectEntity([
            'title' => 'Originele Motie',
            'text'  => 'Originele motietekst.',
        ], 'motion-uuid');

        $this->objectService->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls($amendmentEntity, $motionEntity);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(fn($obj) => str_contains($obj['text'] ?? '', 'Vervangende tekst voor artikel 2.')),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($motionEntity);

        $this->service->applyAmendment('motion-uuid', 'amendment-uuid');

    }//end testApplyAmendmentUpdatesMotionText()
}//end class
