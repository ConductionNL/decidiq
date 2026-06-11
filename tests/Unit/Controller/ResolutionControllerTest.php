<?php

/**
 * Unit tests for ResolutionController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ResolutionController;
use OCA\Decidesk\Service\ResolutionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResolutionController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
 */
class ResolutionControllerTest extends TestCase
{


    /**
     * Build a controller wired against the given service.
     *
     * @param ResolutionService    $service       Service double
     * @param array<string, mixed> $requestParams Params returned by IRequest
     * @param bool                 $authenticated Whether the session has a user
     *
     * @return ResolutionController
     */
    private function makeController(ResolutionService $service, array $requestParams=[], bool $authenticated=true): ResolutionController
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

        return new ResolutionController($request, $service, $session);

    }//end makeController()


    /**
     * Anonymous calls are rejected.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(ResolutionService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->propose('m1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->amend('r1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->openVote('r1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->conclude('r1')->getStatus());

    }//end testAnonymousAccessRejected()


    /**
     * propose returns 201 on success.
     *
     * @return void
     */
    public function testProposeReturns201(): void
    {
        $service = $this->createMock(ResolutionService::class);
        $service->method('propose')->willReturn(
            [
                'success'    => true,
                'resolution' => ['id' => 'r1'],
                'message'    => 'ok',
            ]
        );

        $controller = $this->makeController($service, requestParams: ['title' => 'Approve budget']);

        $this->assertSame(Http::STATUS_CREATED, $controller->propose('m1')->getStatus());

    }//end testProposeReturns201()


    /**
     * openVote forwards quorum failure as 422.
     *
     * @return void
     */
    public function testOpenVoteMapsQuorumFailureTo422(): void
    {
        $service = $this->createMock(ResolutionService::class);
        $service->method('openVote')->willReturn(
            [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Quorum not met (2/5, threshold 3).',
            ]
        );

        $controller = $this->makeController($service);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->openVote('r1')->getStatus());

    }//end testOpenVoteMapsQuorumFailureTo422()


    /**
     * conclude returns tally + resolution shape.
     *
     * @return void
     */
    public function testConcludeReturnsTallyShape(): void
    {
        $service = $this->createMock(ResolutionService::class);
        $service->method('conclude')->willReturn(
            [
                'success'    => true,
                'resolution' => ['id' => 'r1', 'status' => 'adopted'],
                'tally'      => ['in-favor' => 2, 'against' => 1, 'abstain' => 0, 'absent' => 0, 'recused-due-to-conflict' => 0],
                'message'    => 'Resolution adopted.',
            ]
        );

        $controller = $this->makeController($service);
        $response   = $controller->conclude('r1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(2, $data['tally']['in-favor']);
        $this->assertSame('adopted', $data['resolution']['status']);

    }//end testConcludeReturnsTallyShape()


    /**
     * conclude maps not-found to 404.
     *
     * @return void
     */
    public function testConcludeNotFoundMapsTo404(): void
    {
        $service = $this->createMock(ResolutionService::class);
        $service->method('conclude')->willReturn(
            [
                'success'    => false,
                'resolution' => null,
                'tally'      => [],
                'message'    => 'Resolution not found.',
            ]
        );

        $controller = $this->makeController($service);
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->conclude('r-x')->getStatus());

    }//end testConcludeNotFoundMapsTo404()


}//end class
