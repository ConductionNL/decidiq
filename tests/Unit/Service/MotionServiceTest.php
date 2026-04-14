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
    private MockObject $objectService;

    /**
     * Set up mocks and service.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(originalClassName: \stdClass::class);

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->container
            ->method('get')
            ->willReturnCallback(
                    function (string $id) {
                        if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                            return $this->objectService;
                        }

                        return $this->createMock(originalClassName: \stdClass::class);
                    }
                    );

        $this->logger  = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service = new MotionService(container: $this->container, logger: $this->logger);
    }//end setUp()

    /**
     * Test that a valid lifecycle transition is allowed.
     *
     * Submitted → debating is a valid transition.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testTransitionLifecycleAllowed(): void
    {
        $motion = [
            'id'        => 'motion-1',
            'lifecycle' => 'submitted',
            'status'    => 'submitted',
            'title'     => 'Test Motion',
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($motion);

        $savedObject = null;
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(
                    function ($register, $schema, $object) use (&$savedObject) {
                        $savedObject = $object;
                        return $object;
                    }
                    );

        $this->service->transitionLifecycle(objectId: 'motion-1', objectType: 'motion', newState: 'debating', actorId: 'user1');

        $this->assertEquals(expected: 'debating', actual: $savedObject['lifecycle']);
        $this->assertEquals(expected: 'debating', actual: $savedObject['status']);
    }//end testTransitionLifecycleAllowed()

    /**
     * Test that an invalid lifecycle transition throws an exception.
     *
     * Submitted → adopted is not a valid direct transition.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testTransitionLifecycleBlocked(): void
    {
        $motion = [
            'id'        => 'motion-2',
            'lifecycle' => 'submitted',
            'status'    => 'submitted',
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($motion);

        $this->expectException(exception: \InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/not allowed/');

        $this->service->transitionLifecycle(objectId: 'motion-2', objectType: 'motion', newState: 'adopted', actorId: 'user1');
    }//end testTransitionLifecycleBlocked()

    /**
     * Test that transitionLifecycle throws RuntimeException when object is not found.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testTransitionLifecycleObjectNotFound(): void
    {
        $this->objectService
            ->method('getObject')
            ->willReturn(null);

        $this->expectException(exception: \RuntimeException::class);

        $this->service->transitionLifecycle(objectId: 'non-existent', objectType: 'motion', newState: 'debating', actorId: 'user1');
    }//end testTransitionLifecycleObjectNotFound()

    /**
     * Test that addCoSigner appends the display name to coSigners.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testAddCoSignerAppendsName(): void
    {
        $motion = [
            'id'        => 'motion-3',
            'lifecycle' => 'submitted',
            'title'     => 'Test Motion',
            'coSigners' => ['Alice'],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($motion);

        $savedObject = null;
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(
                    function ($register, $schema, $object) use (&$savedObject) {
                        $savedObject = $object;
                        return $object;
                    }
                    );

        $this->service->addCoSigner(motionId: 'motion-3', participantDisplayName: 'Bob');

        $this->assertContains(needle: 'Bob', haystack: $savedObject['coSigners']);
        $this->assertContains(needle: 'Alice', haystack: $savedObject['coSigners']);
    }//end testAddCoSignerAppendsName()

    /**
     * Test that addCoSigner is idempotent — duplicate names are not added.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testAddCoSignerIdempotent(): void
    {
        $motion = [
            'id'        => 'motion-4',
            'lifecycle' => 'submitted',
            'title'     => 'Test Motion',
            'coSigners' => ['Alice'],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($motion);

        // SaveObject should NOT be called since name already exists.
        $this->objectService
            ->expects($this->never())
            ->method('saveObject');

        $this->service->addCoSigner(motionId: 'motion-4', participantDisplayName: 'Alice');
    }//end testAddCoSignerIdempotent()

    /**
     * Test detectConflicts with non-overlapping amendment texts.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testDetectConflictsNoOverlap(): void
    {
        $newAmendment = [
            'id'        => 'amd-new',
            'uuid'      => 'amd-new',
            'lifecycle' => 'submitted',
            'text'      => 'Completely different text that has no overlap with existing content.',
            'title'     => 'New Amendment',
        ];

        $existingAmendment = [
            'id'        => 'amd-existing',
            'uuid'      => 'amd-existing',
            'lifecycle' => 'submitted',
            'text'      => 'Another proposal about unrelated matters concerning green energy policy.',
            'title'     => 'Existing Amendment',
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($newAmendment);

        $this->objectService
            ->method('findObjects')
            ->willReturn([$existingAmendment]);

        // SaveObject should NOT be called — no conflict notes added.
        $this->objectService
            ->expects($this->never())
            ->method('saveObject');

        $this->service->detectConflicts(motionId: 'motion-5', newAmendmentId: 'amd-new');
    }//end testDetectConflictsNoOverlap()

    /**
     * Test detectConflicts with overlapping amendment texts.
     *
     * Both amendments share a 5-word sequence, triggering conflict detection.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testDetectConflictsWithOverlap(): void
    {
        $sharedPhrase = 'de gemeente haarlemmermeer haar klimaatdoelstelling';

        $newAmendment = [
            'id'        => 'amd-overlap-new',
            'uuid'      => 'amd-overlap-new',
            'lifecycle' => 'submitted',
            'text'      => "In de motie wordt '{$sharedPhrase} van 50%' gewijzigd.",
            'title'     => 'New Overlapping Amendment',
            'notes'     => [],
        ];

        $existingAmendment = [
            'id'        => 'amd-overlap-existing',
            'uuid'      => 'amd-overlap-existing',
            'lifecycle' => 'submitted',
            'text'      => "Aan de motie wordt toegevoegd dat {$sharedPhrase} extra aandacht verdient.",
            'title'     => 'Existing Overlapping Amendment',
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($newAmendment);

        $this->objectService
            ->method('findObjects')
            ->willReturn([$existingAmendment]);

        $savedObject = null;
        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                    function ($register, $schema, $object) use (&$savedObject) {
                        $savedObject = $object;
                        return $object;
                    }
                    );

        $this->service->detectConflicts(motionId: 'motion-6', newAmendmentId: 'amd-overlap-new');

        $this->assertNotNull(actual: $savedObject);
        $conflictNotes = array_filter(
            $savedObject['notes'] ?? [],
            fn($n) => str_starts_with($n['title'] ?? '', 'Conflict:')
        );
        $this->assertNotEmpty(actual: $conflictNotes);
    }//end testDetectConflictsWithOverlap()

    /**
     * Test applyAmendment appends amendment text to motion text.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
     *
     * @return void
     */
    public function testApplyAmendmentUpdatesMotionText(): void
    {
        $motion = [
            'id'   => 'motion-7',
            'text' => 'Original motion text.',
        ];

        $amendment = [
            'id'    => 'amd-7',
            'title' => 'Date Change Amendment',
            'text'  => 'Change date to 1 July.',
        ];

        $callCount = 0;
        $this->objectService
            ->method('getObject')
            ->willReturnCallback(
                    function ($register, $schema, $uuid) use ($motion, $amendment, &$callCount) {
                        if ($schema === 'motion') {
                            return $motion;
                        }

                        if ($schema === 'amendment') {
                            return $amendment;
                        }

                        return null;
                    }
                    );

        $savedObject = null;
        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                    function ($register, $schema, $object) use (&$savedObject) {
                        $savedObject = $object;
                        return $object;
                    }
                    );

        $this->service->applyAmendment(motionId: 'motion-7', amendmentId: 'amd-7');

        $this->assertStringContainsString(needle: 'Original motion text.', haystack: $savedObject['text']);
        $this->assertStringContainsString(needle: 'Change date to 1 July.', haystack: $savedObject['text']);
        $this->assertStringContainsString(needle: '[Date Change Amendment]', haystack: $savedObject['text']);
    }//end testApplyAmendmentUpdatesMotionText()
}//end class
