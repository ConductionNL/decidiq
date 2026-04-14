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
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MeetingService lifecycle management.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
 */
class MeetingServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var MeetingService
     */
    private MeetingService $service;

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
     * Mock IL10N.
     *
     * @var IL10N&MockObject
     */
    private IL10N&MockObject $l10n;

    /**
     * Mock object service.
     *
     * @var object&MockObject
     */
    private object $objectService;

    /**
     * Mock notification service.
     *
     * @var object&MockObject
     */
    private object $notificationService;

    /**
     * Mock IUserSession for the chair user (userId = 'user-1').
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $chairUserSession;

    /**
     * Set up test fixtures.
     *
     * IUserSession is injected with a chair user (userId = 'user-1') so that
     * assertChairOrSecretary passes whenever participants include 'user-1'.
     * Tests that validate the 403 path create their own service instance.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);
        $this->l10n      = $this->createMock(originalClassName: IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            static fn(string $text, array $params = []) => vsprintf($text, $params)
        );

        $this->objectService       = $this->createMockObjectService();
        $this->notificationService = $this->createMockNotificationService();

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) {
                    return match ($id) {
                        'OCA\OpenRegister\Service\ObjectService'       => $this->objectService,
                        'OCA\OpenRegister\Service\NotificationService' => $this->notificationService,
                        default => throw new \RuntimeException('Unknown service: '.$id),
                    };
                }
            );

        // Inject a chair user so assertChairOrSecretary passes.
        $mockUser = $this->createMock(originalClassName: IUser::class);
        $mockUser->method('getUID')->willReturn('user-1');
        $this->chairUserSession = $this->createMock(originalClassName: IUserSession::class);
        $this->chairUserSession->method('getUser')->willReturn($mockUser);

        $this->service = new MeetingService(
            container: $this->container,
            logger: $this->logger,
            l10n: $this->l10n,
            userSession: $this->chairUserSession,
        );

    }//end setUp()

    /**
     * Test that a valid lifecycle transition succeeds and returns correct states.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionLifecycleScheduleSucceeds(): void
    {
        $meeting = [
            'id'            => 'meeting-1',
            'title'         => 'Test Meeting',
            'lifecycle'     => 'draft',
            'scheduledDate' => '2026-05-01T10:00:00Z',
            'relations'     => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        $chairParticipant = [
            [
                'owner'  => 'user-1',
                'role'   => 'chair',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($chairParticipant);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                'decidesk',
                'meeting',
                $this->callback(static function (array $data): bool {
                    return ($data['lifecycle'] ?? '') === 'scheduled';
                })
            );

        $result = $this->service->transitionLifecycle(meetingId: 'meeting-1', transition: 'schedule');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'draft', actual: $result['previousState']);
        self::assertSame(expected: 'scheduled', actual: $result['currentState']);
        self::assertSame(expected: 'schedule', actual: $result['transition']);

    }//end testTransitionLifecycleScheduleSucceeds()

    /**
     * Test that an invalid transition throws a RuntimeException with code 400.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionLifecycleThrowsOnInvalidTransition(): void
    {
        $meeting = [
            'id'        => 'meeting-1',
            'title'     => 'Test Meeting',
            'lifecycle' => 'draft',
            'relations' => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        $chairParticipant = [
            [
                'owner'  => 'user-1',
                'role'   => 'chair',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($chairParticipant);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->transitionLifecycle(meetingId: 'meeting-1', transition: 'close');

    }//end testTransitionLifecycleThrowsOnInvalidTransition()

    /**
     * Test that a non-chair user gets 403 on lifecycle transition.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionLifecycleThrowsForbiddenForNonChair(): void
    {
        $meeting = [
            'id'        => 'meeting-1',
            'title'     => 'Test Meeting',
            'lifecycle' => 'scheduled',
            'relations' => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        // Participant is a regular member, not chair.
        $memberParticipant = [
            [
                'owner'  => 'user-1',
                'role'   => 'member',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($memberParticipant);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionCode(403);

        $this->service->transitionLifecycle(meetingId: 'meeting-1', transition: 'open');

    }//end testTransitionLifecycleThrowsForbiddenForNonChair()

    /**
     * Test that scheduling requires a scheduledDate to be set.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionScheduleRequiresScheduledDate(): void
    {
        $meeting = [
            'id'            => 'meeting-1',
            'title'         => 'Test Meeting',
            'lifecycle'     => 'draft',
            'scheduledDate' => '',
            'relations'     => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        $chairParticipant = [
            [
                'owner'  => 'user-1',
                'role'   => 'chair',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($chairParticipant);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->transitionLifecycle(meetingId: 'meeting-1', transition: 'schedule');

    }//end testTransitionScheduleRequiresScheduledDate()

    /**
     * Test that getUserRole returns the correct role for a participant.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testGetUserRoleReturnsCorrectRole(): void
    {
        $meeting = [
            'id'        => 'meeting-1',
            'relations' => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        $participants = [
            [
                'owner'  => 'user-1',
                'role'   => 'secretary',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($participants);

        $result = $this->service->getUserRole(meetingId: 'meeting-1');

        self::assertSame(expected: 'secretary', actual: $result['role']);

    }//end testGetUserRoleReturnsCorrectRole()

    /**
     * Test that getUserRole returns 'none' when user is not a participant.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testGetUserRoleReturnsNoneForNonParticipant(): void
    {
        $meeting = [
            'id'        => 'meeting-1',
            'relations' => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        // No participants match user-1.
        $participants = [
            [
                'owner'  => 'user-other',
                'role'   => 'chair',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($participants);

        $result = $this->service->getUserRole(meetingId: 'meeting-1');

        self::assertSame(expected: 'none', actual: $result['role']);

    }//end testGetUserRoleReturnsNoneForNonParticipant()

    /**
     * Test that a terminal state (closed) cannot be transitioned.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionFromClosedThrows(): void
    {
        $meeting = [
            'id'        => 'meeting-1',
            'title'     => 'Test Meeting',
            'lifecycle' => 'closed',
            'relations' => [
                ['schema' => 'governance-body', 'id' => 'gb-1'],
            ],
        ];

        $chairParticipant = [
            [
                'owner'  => 'user-1',
                'role'   => 'chair',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturn($chairParticipant);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionCode(400);

        $this->service->transitionLifecycle(meetingId: 'meeting-1', transition: 'open');

    }//end testTransitionFromClosedThrows()

    /**
     * Create a mock ObjectService with getObject, getObjects, and saveObject methods.
     *
     * @return object&MockObject
     */
    private function createMockObjectService(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'getObjects', 'saveObject'])
            ->getMock();

        return $mock;

    }//end createMockObjectService()

    /**
     * Create a mock NotificationService with sendNotification method.
     *
     * @return object&MockObject
     */
    private function createMockNotificationService(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['sendNotification'])
            ->getMock();

        return $mock;

    }//end createMockNotificationService()
}//end class
