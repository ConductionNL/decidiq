<?php

/**
 * Unit tests for BoardMemberController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\BoardMemberController;
use OCA\Decidesk\Service\BoardMemberService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BoardMemberController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
 */
class BoardMemberControllerTest extends TestCase
{


    /**
     * Build a controller wired against the given service double.
     *
     * @param BoardMemberService    $service       Service double
     * @param array<string, mixed>  $requestParams Params returned by getParams()/getParam()
     * @param bool                  $authenticated Whether the session has a user
     *
     * @return BoardMemberController
     */
    private function makeController(BoardMemberService $service, array $requestParams=[], bool $authenticated=true): BoardMemberController
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

        return new BoardMemberController($request, $service, $session);

    }//end makeController()


    /**
     * Anonymous access returns 401 on every endpoint.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(BoardMemberService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index('b1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->invite('b1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->remove('m1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->changeRole('m1')->getStatus());

    }//end testAnonymousAccessRejected()


    /**
     * invite forwards the role payload to the service and returns 201.
     *
     * @return void
     */
    public function testInviteReturns201OnSuccess(): void
    {
        $service = $this->createMock(BoardMemberService::class);
        $service->expects($this->once())
            ->method('invite')
            ->with(
                $this->equalTo('b1'),
                $this->callback(static function (array $data): bool {
                    return (($data['rol'] ?? null) === 'chairman');
                })
            )
            ->willReturn(
                [
                    'success' => true,
                    'member'  => ['id' => 'm1', 'rol' => 'chairman'],
                    'message' => 'ok',
                ]
            );

        $controller = $this->makeController(
            $service,
            requestParams: ['rol' => 'chairman', 'persoonKoppeling' => 'p1']
        );

        $this->assertSame(Http::STATUS_CREATED, $controller->invite('b1')->getStatus());

    }//end testInviteReturns201OnSuccess()


    /**
     * invite validation failure → 422.
     *
     * @return void
     */
    public function testInviteValidationErrorMapsTo422(): void
    {
        $service = $this->createMock(BoardMemberService::class);
        $service->method('invite')->willReturn(
            ['success' => false, 'member' => null, 'message' => 'Role is required and must be one of: ...']
        );

        $controller = $this->makeController($service);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->invite('b1')->getStatus());

    }//end testInviteValidationErrorMapsTo422()


    /**
     * changeRole requires the `role` body param.
     *
     * @return void
     */
    public function testChangeRoleRequiresRoleParam(): void
    {
        $service    = $this->createMock(BoardMemberService::class);
        $controller = $this->makeController($service, requestParams: []);

        $response = $controller->changeRole('m1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testChangeRoleRequiresRoleParam()


    /**
     * remove maps "not found" to 404.
     *
     * @return void
     */
    public function testRemoveMissingMemberMapsTo404(): void
    {
        $service = $this->createMock(BoardMemberService::class);
        $service->method('remove')->willReturn(
            ['success' => false, 'member' => null, 'message' => 'Board member not found.']
        );

        $controller = $this->makeController($service);

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->remove('m-x')->getStatus());

    }//end testRemoveMissingMemberMapsTo404()


}//end class
