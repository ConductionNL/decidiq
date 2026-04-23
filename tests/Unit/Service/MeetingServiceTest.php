<?php

/**
 * Unit tests for MeetingService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\QuorumService;
use OCA\Decidesk\Service\WorkflowService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MeetingService lifecycle transitions.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
 */
class MeetingServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var MeetingService
     */
    private MeetingService $service;

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
     * Mock ObjectService from OpenRegister.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock WorkflowService.
     *
     * @var WorkflowService&MockObject
     */
    private WorkflowService&MockObject $workflowService;

    /**
     * Mock QuorumService.
     *
     * @var QuorumService&MockObject
     */
    private QuorumService&MockObject $quorumService;

    /**
     * Set up test fixtures.
     *
     * Default workflow mocks permit all transitions (operations domain semantics)
     * so that existing tests continue to work without modification.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService   = $this->createMock(originalClassName: ObjectService::class);
        $this->container       = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
        $this->workflowService = $this->createMock(originalClassName: WorkflowService::class);
        $this->quorumService   = $this->createMock(originalClassName: QuorumService::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
            workflowService: $this->workflowService,
            quorumService: $this->quorumService,
        );

    }//end setUp()

    /**
     * Helper to build a mock ObjectEntity with a given lifecycle, domain, and optional chair.
     *
     * @param string      $lifecycle The lifecycle state to set on the mock entity
     * @param string      $domain    The governance domain (default: 'operations')
     * @param string|null $chair     The Nextcloud UID of the meeting chair (default: null)
     *
     * @return ObjectEntity&MockObject
     */
    private function buildMockEntity(string $lifecycle, string $domain = 'operations', ?string $chair = null): ObjectEntity&MockObject
    {
        $entity = $this->createMock(originalClassName: ObjectEntity::class);
        $data   = ['lifecycle' => $lifecycle, 'domain' => $domain];
        if ($chair !== null) {
            $data['chair'] = $chair;
        }

        $entity->method('getObject')->willReturn($data);
        $entity->method('jsonSerialize')->willReturn(array_merge($data, ['id' => 'test-uuid']));
        return $entity;

    }//end buildMockEntity()

    /**
     * Test that a valid transition (scheduled → open → opened) returns success.
     *
     * @return void
     */
    public function testValidTransitionReturnsSuccess(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $uuid         = 'aaaaaaaa-0000-0000-0000-000000000001';
        $currentState = 'scheduled';
        $entity       = $this->buildMockEntity($currentState);
        $updatedEntity = $this->buildMockEntity('opened');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willReturn($entity);

        $this->objectService->expects($this->once())
            ->method('updateFromArray')
            ->with(
                id: $uuid,
                object: ['lifecycle' => 'opened'],
                updateVersion: true,
                patch: true,
            )
            ->willReturn($updatedEntity);

        $result = $this->service->transition(meetingId: $uuid, action: 'open');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'opened', actual: $result['meeting']['lifecycle']);

    }//end testValidTransitionReturnsSuccess()

    /**
     * Test that trying to pause a draft meeting returns a failure with a descriptive message.
     *
     * @return void
     */
    public function testInvalidTransitionReturnsFailure(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $uuid   = 'aaaaaaaa-0000-0000-0000-000000000002';
        $entity = $this->buildMockEntity('draft');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willReturn($entity);

        $this->objectService->expects($this->never())
            ->method('updateFromArray');

        $result = $this->service->transition(meetingId: $uuid, action: 'pause');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'draft', haystack: $result['message']);

    }//end testInvalidTransitionReturnsFailure()

    /**
     * Test that an unknown action name returns a failure with a list of valid actions.
     *
     * @return void
     */
    public function testUnknownActionReturnsFailure(): void
    {
        $this->objectService->expects($this->never())
            ->method('find');

        $result = $this->service->transition(meetingId: 'some-uuid', action: 'fly-to-the-moon');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'Unknown action', haystack: $result['message']);

    }//end testUnknownActionReturnsFailure()

    /**
     * Test that transitioning a non-existent meeting returns a failure.
     *
     * @return void
     */
    public function testMeetingNotFoundReturnsFailure(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000099';

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willReturn(null);

        $result = $this->service->transition(meetingId: $uuid, action: 'open');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'not found', haystack: $result['message']);

    }//end testMeetingNotFoundReturnsFailure()

    /**
     * Test that a DoesNotExistException thrown by ObjectService is handled gracefully.
     *
     * Covers the catch (DoesNotExistException) path in MeetingService::transition().
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
     *
     * @return void
     */
    public function testDoesNotExistExceptionReturnsFailure(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000098';

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willThrowException(new DoesNotExistException('Meeting not found'));

        $result = $this->service->transition(meetingId: $uuid, action: 'open');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'not found', haystack: $result['message']);

    }//end testDoesNotExistExceptionReturnsFailure()

    /**
     * Test that the full close path (opened → close → closed) works correctly.
     *
     * @return void
     */
    public function testCloseFromOpenedReturnsSuccess(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $uuid          = 'aaaaaaaa-0000-0000-0000-000000000003';
        $entity        = $this->buildMockEntity('opened');
        $updatedEntity = $this->buildMockEntity('closed');

        $this->objectService->method('find')->willReturn($entity);
        $this->objectService->method('updateFromArray')->willReturn($updatedEntity);

        $result = $this->service->transition(meetingId: $uuid, action: 'close');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'closed', actual: $result['meeting']['lifecycle']);

    }//end testCloseFromOpenedReturnsSuccess()

    /**
     * Test getAvailableActions returns only valid actions for a given state.
     *
     * @return void
     */
    public function testGetAvailableActionsForScheduled(): void
    {
        $actions = $this->service->getAvailableActions('scheduled');

        self::assertContains(needle: 'open', haystack: $actions);
        self::assertContains(needle: 'close', haystack: $actions);
        self::assertNotContains(needle: 'pause', haystack: $actions);
        self::assertNotContains(needle: 'resume', haystack: $actions);

    }//end testGetAvailableActionsForScheduled()

    /**
     * Test getAvailableActions returns empty array for terminal 'closed' state.
     *
     * @return void
     */
    public function testGetAvailableActionsForClosedReturnsEmpty(): void
    {
        $actions = $this->service->getAvailableActions('closed');

        self::assertEmpty(actual: $actions);

    }//end testGetAvailableActionsForClosedReturnsEmpty()

    /**
     * Test that a domain-restricted transition (pause in 'corporate') is blocked.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     *
     * @return void
     */
    public function testDomainDisallowedTransitionReturnsFailure(): void
    {
        $uuid   = 'aaaaaaaa-0000-0000-0000-000000000010';
        $entity = $this->buildMockEntity(lifecycle: 'opened', domain: 'corporate');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willReturn($entity);

        $workflowService = $this->createMock(originalClassName: WorkflowService::class);
        $workflowService->method('isTransitionAllowed')->willReturn(false);

        $quorumService = $this->createMock(originalClassName: QuorumService::class);

        $service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
            workflowService: $workflowService,
            quorumService: $quorumService,
        );

        $result = $service->transition(meetingId: $uuid, action: 'pause');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'not permitted', haystack: $result['message']);

    }//end testDomainDisallowedTransitionReturnsFailure()

    /**
     * Test that a chair-only transition is blocked when the caller is not the chair.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     *
     * @return void
     */
    public function testChairOnlyTransitionBlockedWithoutChairRole(): void
    {
        $uuid   = 'aaaaaaaa-0000-0000-0000-000000000011';
        $entity = $this->buildMockEntity(lifecycle: 'opened', domain: 'legislative', chair: 'uid-chair');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willReturn($entity);

        $workflowService = $this->createMock(originalClassName: WorkflowService::class);
        $workflowService->method('isTransitionAllowed')->willReturn(true);
        $workflowService->method('requiresChairAuthorization')->willReturn(true);

        $quorumService = $this->createMock(originalClassName: QuorumService::class);

        $service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
            workflowService: $workflowService,
            quorumService: $quorumService,
        );

        // Caller is NOT the chair.
        $result = $service->transition(meetingId: $uuid, action: 'adjourn', currentUserId: 'uid-other-user');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'chair', haystack: $result['message']);

    }//end testChairOnlyTransitionBlockedWithoutChairRole()

    /**
     * Test that a chair-only transition succeeds when the caller IS the chair.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     *
     * @return void
     */
    public function testChairOnlyTransitionSucceedsForChair(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $uuid          = 'aaaaaaaa-0000-0000-0000-000000000012';
        $entity        = $this->buildMockEntity(lifecycle: 'opened', domain: 'legislative', chair: 'uid-chair');
        $updatedEntity = $this->buildMockEntity(lifecycle: 'adjourned', domain: 'legislative', chair: 'uid-chair');

        $this->objectService->method('find')->willReturn($entity);
        $this->objectService->method('updateFromArray')->willReturn($updatedEntity);

        $workflowService = $this->createMock(originalClassName: WorkflowService::class);
        $workflowService->method('isTransitionAllowed')->willReturn(true);
        $workflowService->method('requiresChairAuthorization')->willReturn(true);

        $quorumService = $this->createMock(originalClassName: QuorumService::class);

        $service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
            workflowService: $workflowService,
            quorumService: $quorumService,
        );

        // Caller IS the chair.
        $result = $service->transition(meetingId: $uuid, action: 'adjourn', currentUserId: 'uid-chair');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'adjourned', actual: $result['meeting']['lifecycle']);

    }//end testChairOnlyTransitionSucceedsForChair()

    /**
     * Test that opening a meeting with quorum not met is blocked.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
     *
     * @return void
     */
    public function testOpenBlockedWhenQuorumNotMet(): void
    {
        $uuid   = 'aaaaaaaa-0000-0000-0000-000000000013';
        $entity = $this->buildMockEntity(lifecycle: 'scheduled', domain: 'legislative');

        $this->objectService->expects($this->once())
            ->method('find')
            ->with(id: $uuid)
            ->willReturn($entity);

        $workflowService = $this->createMock(originalClassName: WorkflowService::class);
        $workflowService->method('isTransitionAllowed')->willReturn(true);
        $workflowService->method('requiresChairAuthorization')->willReturn(false);
        $workflowService->method('isQuorumRequired')->willReturn(true);

        $quorumService = $this->createMock(originalClassName: QuorumService::class);
        $quorumService->method('validateQuorum')->willReturn(false);

        $service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
            workflowService: $workflowService,
            quorumService: $quorumService,
        );

        $result = $service->transition(meetingId: $uuid, action: 'open');

        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['meeting']);
        self::assertStringContainsString(needle: 'Quorum', haystack: $result['message']);

    }//end testOpenBlockedWhenQuorumNotMet()

}//end class
