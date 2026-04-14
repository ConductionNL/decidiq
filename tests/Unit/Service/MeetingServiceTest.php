<?php

/**
 * Unit tests for MeetingService.
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
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(originalClassName: ObjectService::class);
        $this->container     = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Helper to build a mock ObjectEntity with a given lifecycle value.
     *
     * @param string $lifecycle The lifecycle state to set on the mock entity
     *
     * @return ObjectEntity&MockObject
     */
    private function buildMockEntity(string $lifecycle): ObjectEntity&MockObject
    {
        $entity = $this->createMock(originalClassName: ObjectEntity::class);
        $entity->method('getObject')->willReturn(['lifecycle' => $lifecycle]);
        $entity->method('jsonSerialize')->willReturn(['lifecycle' => $lifecycle, 'id' => 'test-uuid']);
        return $entity;

    }//end buildMockEntity()

    /**
     * Test that a valid transition (scheduled → open → opened) returns success.
     *
     * @return void
     */
    public function testValidTransitionReturnsSuccess(): void
    {
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
        self::assertStringContainsString(needle: 'fly-to-the-moon', haystack: $result['message']);

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
     * Test that the full close path (opened → close → closed) works correctly.
     *
     * @return void
     */
    public function testCloseFromOpenedReturnsSuccess(): void
    {
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

}//end class
