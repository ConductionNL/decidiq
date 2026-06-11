<?php

/**
 * Unit tests for BoardMeetingController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\BoardMeetingController;
use OCA\Decidesk\Service\BoardMeetingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BoardMeetingController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
 */
class BoardMeetingControllerTest extends TestCase
{


    /**
     * Build a controller around the given service.
     *
     * @param BoardMeetingService  $service       Service double
     * @param array<string, mixed> $requestParams Params returned by IRequest
     * @param bool                 $authenticated Session-has-user flag
     *
     * @return BoardMeetingController
     */
    private function makeController(BoardMeetingService $service, array $requestParams=[], bool $authenticated=true): BoardMeetingController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($requestParams);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null) use ($requestParams): mixed {
                return ($requestParams[$key] ?? $default);
            }
        );

        $session = $this->createMock(IUserSession::class);
        if ($authenticated === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('alice');
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        return new BoardMeetingController($request, $service, $session);

    }//end makeController()


    /**
     * Anonymous calls are rejected.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(BoardMeetingService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->schedule('b1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->sendNotice('m1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->transition('m1')->getStatus());

    }//end testAnonymousAccessRejected()


    /**
     * schedule returns 201 on success.
     *
     * @return void
     */
    public function testScheduleReturns201(): void
    {
        $service = $this->createMock(BoardMeetingService::class);
        $service->expects($this->once())
            ->method('schedule')
            ->willReturn(
                [
                    'success' => true,
                    'meeting' => ['id' => 'm1'],
                    'message' => 'ok',
                ]
            );

        $controller = $this->makeController($service, ['meetingDate' => '2026-09-01']);
        $this->assertSame(Http::STATUS_CREATED, $controller->schedule('b1')->getStatus());

    }//end testScheduleReturns201()


    /**
     * sendNotice forwards the actor UID from the session.
     *
     * @return void
     */
    public function testSendNoticeForwardsActor(): void
    {
        $service = $this->createMock(BoardMeetingService::class);
        $service->expects($this->once())
            ->method('sendNotice')
            ->with('m1', 'alice')
            ->willReturn(['success' => true, 'meeting' => ['id' => 'm1'], 'message' => 'ok']);

        $controller = $this->makeController($service);
        $this->assertSame(Http::STATUS_OK, $controller->sendNotice('m1')->getStatus());

    }//end testSendNoticeForwardsActor()


    /**
     * transition requires the action body param.
     *
     * @return void
     */
    public function testTransitionRequiresAction(): void
    {
        $service    = $this->createMock(BoardMeetingService::class);
        $controller = $this->makeController($service);

        $response = $controller->transition('m1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testTransitionRequiresAction()


    /**
     * transition forwards the action and maps service failure → 422.
     *
     * @return void
     */
    public function testTransitionForwardsActionAndMapsFailure(): void
    {
        $service = $this->createMock(BoardMeetingService::class);
        $service->expects($this->once())
            ->method('runLifecycleTransition')
            ->with('m1', 'open')
            ->willReturn(['success' => false, 'meeting' => null, 'message' => "Cannot 'open' a meeting in 'closed' state."]);

        $controller = $this->makeController($service, ['action' => 'open']);

        $response = $controller->transition('m1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testTransitionForwardsActionAndMapsFailure()


}//end class
