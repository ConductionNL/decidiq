<?php
/**
 * Unit tests for ProxyVoteController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ProxyVoteController;
use OCA\Decidesk\Service\ProxyVoteService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProxyVoteController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class ProxyVoteControllerTest extends TestCase
{


    /**
     * Build a controller wired to the supplied service and params.
     *
     * @param ProxyVoteService     $service       Service double
     * @param array<string, mixed> $requestParams Params returned by IRequest
     * @param bool                 $authenticated Whether session has a user
     *
     * @return ProxyVoteController
     */
    private function makeController(ProxyVoteService $service, array $requestParams=[], bool $authenticated=true): ProxyVoteController
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

        return new ProxyVoteController($request, $service, $session);

    }//end makeController()


    /**
     * register requires authentication.
     *
     * @return void
     */
    public function testRegisterRequiresAuth(): void
    {
        $service    = $this->createMock(ProxyVoteService::class);
        $controller = $this->makeController($service, authenticated: false);

        $response = $controller->register();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testRegisterRequiresAuth()


    /**
     * register rejects missing fields.
     *
     * @return void
     */
    public function testRegisterRejectsMissingFields(): void
    {
        $service    = $this->createMock(ProxyVoteService::class);
        $controller = $this->makeController($service, requestParams: ['meetingId' => 'm-1']);

        $response = $controller->register();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testRegisterRejectsMissingFields()


    /**
     * register returns 201 on success.
     *
     * @return void
     */
    public function testRegisterReturns201OnSuccess(): void
    {
        $service = $this->createMock(ProxyVoteService::class);
        $service->expects($this->once())->method('register')
            ->with('m-1', 'g-1', 'h-1', $this->anything())
            ->willReturn(['success' => true, 'proxy' => ['id' => 'p-1'], 'message' => 'ok']);

        $controller = $this->makeController(
            $service,
            requestParams: ['meetingId' => 'm-1', 'grantorId' => 'g-1', 'holderId' => 'h-1']
        );

        $response = $controller->register();
        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());

    }//end testRegisterReturns201OnSuccess()


    /**
     * index requires meetingId.
     *
     * @return void
     */
    public function testIndexRequiresMeetingId(): void
    {
        $service    = $this->createMock(ProxyVoteService::class);
        $controller = $this->makeController($service);

        $response = $controller->index();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testIndexRequiresMeetingId()


    /**
     * index returns results from the service.
     *
     * @return void
     */
    public function testIndexReturnsResults(): void
    {
        $service = $this->createMock(ProxyVoteService::class);
        $service->expects($this->once())->method('forMeeting')
            ->with('m-1', 'active')
            ->willReturn(['success' => true, 'proxies' => [['id' => 'p-1']], 'count' => 1]);

        $controller = $this->makeController(
            $service,
            requestParams: ['meetingId' => 'm-1', 'status' => 'active']
        );

        $response = $controller->index();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['total']);

    }//end testIndexReturnsResults()


    /**
     * suspend delegates to the service and returns 200 on success.
     *
     * @return void
     */
    public function testSuspendDelegates(): void
    {
        $service = $this->createMock(ProxyVoteService::class);
        $service->expects($this->once())->method('suspend')
            ->with('p-1', 'alice')
            ->willReturn(['success' => true, 'proxy' => ['id' => 'p-1', 'proxyStatus' => 'suspended'], 'message' => 'ok']);

        $controller = $this->makeController($service);
        $response   = $controller->suspend('p-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testSuspendDelegates()


    /**
     * revoke delegates to the service and returns 200 on success.
     *
     * @return void
     */
    public function testRevokeDelegates(): void
    {
        $service = $this->createMock(ProxyVoteService::class);
        $service->expects($this->once())->method('revoke')
            ->with('p-1', 'alice')
            ->willReturn(['success' => true, 'proxy' => ['id' => 'p-1', 'proxyStatus' => 'revoked'], 'message' => 'ok']);

        $controller = $this->makeController($service);
        $response   = $controller->revoke('p-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testRevokeDelegates()


}//end class
