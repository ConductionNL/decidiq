<?php

/**
 * Unit tests for BoardController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\BoardController;
use OCA\Decidesk\Service\BoardService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BoardController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
 */
class BoardControllerTest extends TestCase
{


    /**
     * Build a BoardController wired against the given service and request.
     *
     * @param BoardService          $service        BoardService double
     * @param array<string, mixed>  $requestParams  getParams() return value
     * @param bool                  $authenticated  Whether the session has a user
     *
     * @return BoardController
     */
    private function makeController(BoardService $service, array $requestParams=[], bool $authenticated=true): BoardController
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

        return new BoardController($request, $service, $session);

    }//end makeController()


    /**
     * Anonymous calls are rejected with 401 on every endpoint.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(BoardService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('b1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->create()->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->update('b1')->getStatus());

    }//end testAnonymousAccessRejected()


    /**
     * index returns the list shape.
     *
     * @return void
     */
    public function testIndexReturnsBoardList(): void
    {
        $service = $this->createMock(BoardService::class);
        $service->expects($this->once())
            ->method('list')
            ->with($this->equalTo(['type' => 'audit-committee']))
            ->willReturn(
                [
                    'success' => true,
                    'boards'  => [['id' => 'b1']],
                    'count'   => 1,
                    'message' => 'ok',
                ]
            );

        $controller = $this->makeController($service, requestParams: ['type' => 'audit-committee']);
        $response   = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['total']);

    }//end testIndexReturnsBoardList()


    /**
     * show forwards a not-found from the service.
     *
     * @return void
     */
    public function testShowMapsNotFoundTo404(): void
    {
        $service = $this->createMock(BoardService::class);
        $service->method('get')->willReturn(
            [
                'success' => false,
                'board'   => null,
                'message' => 'Board not found.',
            ]
        );

        $controller = $this->makeController($service);
        $response   = $controller->show('does-not-exist');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowMapsNotFoundTo404()


    /**
     * create maps validation failures to 422.
     *
     * @return void
     */
    public function testCreateValidationErrorMapsTo422(): void
    {
        $service = $this->createMock(BoardService::class);
        $service->method('create')->willReturn(
            [
                'success' => false,
                'board'   => null,
                'message' => 'Board name is required.',
            ]
        );

        $controller = $this->makeController($service);
        $response   = $controller->create();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testCreateValidationErrorMapsTo422()


    /**
     * create returns 201 on success.
     *
     * @return void
     */
    public function testCreateSucceedsReturns201(): void
    {
        $service = $this->createMock(BoardService::class);
        $service->method('create')->willReturn(
            [
                'success' => true,
                'board'   => ['id' => 'b1'],
                'message' => 'Board created.',
            ]
        );

        $controller = $this->makeController(
            $service,
            requestParams: ['name' => 'RvC Acme', 'type' => 'raad-van-commissarissen']
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());

    }//end testCreateSucceedsReturns201()


    /**
     * update strips id from the body before forwarding.
     *
     * @return void
     */
    public function testUpdateStripsRouteIdFromPayload(): void
    {
        $service = $this->createMock(BoardService::class);
        $service->expects($this->once())
            ->method('update')
            ->with(
                $this->equalTo('b1'),
                $this->callback(static function (array $data): bool {
                    return (isset($data['id']) === false && ($data['name'] ?? null) === 'Renamed');
                })
            )
            ->willReturn(
                [
                    'success' => true,
                    'board'   => ['id' => 'b1', 'name' => 'Renamed'],
                    'message' => 'Board updated.',
                ]
            );

        $controller = $this->makeController(
            $service,
            requestParams: ['id' => 'b1', 'name' => 'Renamed']
        );

        $response = $controller->update('b1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testUpdateStripsRouteIdFromPayload()


}//end class
