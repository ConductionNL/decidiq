<?php

/**
 * Unit tests for BoardMaterialController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\BoardMaterialController;
use OCA\Decidesk\Service\BoardMaterialAuthorizationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BoardMaterialController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 */
class BoardMaterialControllerTest extends TestCase
{


    /**
     * Build a controller wired with the given service double.
     *
     * @param BoardMaterialAuthorizationService $service       Service double
     * @param array<string, mixed>              $requestParams Params returned by IRequest
     * @param bool                              $authenticated Session has user
     *
     * @return BoardMaterialController
     */
    private function makeController(BoardMaterialAuthorizationService $service, array $requestParams=[], bool $authenticated=true): BoardMaterialController
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

        return new BoardMaterialController($request, $service, $session);

    }//end makeController()


    /**
     * index forwards role filter and returns the result shape.
     *
     * @return void
     */
    public function testIndexFiltersByRole(): void
    {
        $service = $this->createMock(BoardMaterialAuthorizationService::class);
        $service->expects($this->once())
            ->method('filterMaterialsByRole')
            ->with('b1', 'chairman')
            ->willReturn([['id' => 'm1'], ['id' => 'm2']]);

        $controller = $this->makeController($service, requestParams: ['role' => 'chairman']);
        $response   = $controller->index('b1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(2, $response->getData()['total']);

    }//end testIndexFiltersByRole()


    /**
     * show denies + logs when canViewMaterial returns false.
     *
     * @return void
     */
    public function testShowLogsAndForbidsWhenNoAccess(): void
    {
        $service = $this->createMock(BoardMaterialAuthorizationService::class);
        $service->expects($this->once())->method('canViewMaterial')->with('m1', 'mat1')->willReturn(false);
        $service->expects($this->once())->method('logMaterialAccess')->with('m1', 'mat1', false);

        $controller = $this->makeController($service, requestParams: ['boardMemberId' => 'm1']);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->show('mat1')->getStatus());

    }//end testShowLogsAndForbidsWhenNoAccess()


    /**
     * show grants + logs when canViewMaterial returns true.
     *
     * @return void
     */
    public function testShowLogsAndGrantsWhenAccess(): void
    {
        $service = $this->createMock(BoardMaterialAuthorizationService::class);
        $service->expects($this->once())->method('canViewMaterial')->with('m1', 'mat1')->willReturn(true);
        $service->expects($this->once())->method('logMaterialAccess')->with('m1', 'mat1', true);

        $controller = $this->makeController($service, requestParams: ['boardMemberId' => 'm1']);
        $response   = $controller->show('mat1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['granted']);

    }//end testShowLogsAndGrantsWhenAccess()


    /**
     * Missing role param on index returns 422.
     *
     * @return void
     */
    public function testIndexRequiresRoleParam(): void
    {
        $service    = $this->createMock(BoardMaterialAuthorizationService::class);
        $controller = $this->makeController($service);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->index('b1')->getStatus());

    }//end testIndexRequiresRoleParam()


    /**
     * Anonymous access is rejected.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(BoardMaterialAuthorizationService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index('b1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('mat1')->getStatus());

    }//end testAnonymousAccessRejected()


}//end class
