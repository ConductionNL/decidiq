<?php

/**
 * Unit tests for AuditLogController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\AuditLogController;
use OCA\Decidesk\Service\AuditLogService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditLogController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
 */
class AuditLogControllerTest extends TestCase
{


    /**
     * Build a controller wired with the given service double + admin flag.
     *
     * @param AuditLogService      $service       Service double
     * @param array<string, mixed> $requestParams Params returned by IRequest
     * @param bool                 $authenticated Session has user
     * @param bool                 $admin         Whether the user is an admin
     *
     * @return AuditLogController
     */
    private function makeController(
        AuditLogService $service,
        array $requestParams=[],
        bool $authenticated=true,
        bool $admin=true,
    ): AuditLogController {
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

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($admin);

        return new AuditLogController($request, $service, $session, $groupManager);

    }//end makeController()


    /**
     * Non-admin callers are rejected with 403.
     *
     * @return void
     */
    public function testNonAdminCallerForbidden(): void
    {
        $service    = $this->createMock(AuditLogService::class);
        $controller = $this->makeController($service, admin: false);

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->index()->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->verify('a')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->export()->getStatus());

    }//end testNonAdminCallerForbidden()


    /**
     * Anonymous calls are rejected with 401.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(AuditLogService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());

    }//end testAnonymousAccessRejected()


    /**
     * index forwards filters and returns total + results.
     *
     * @return void
     */
    public function testIndexForwardsFilters(): void
    {
        $service = $this->createMock(AuditLogService::class);
        $service->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(static function (array $filters): bool {
                    return (($filters['actor'] ?? null) === 'alice'
                        && ($filters['action'] ?? null) === 'vote');
                })
            )
            ->willReturn(
                [
                    'success' => true,
                    'entries' => [['id' => 'e1']],
                    'count'   => 1,
                ]
            );

        $controller = $this->makeController(
            $service,
            requestParams: ['actor' => 'alice', 'action' => 'vote']
        );

        $response = $controller->index();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);

    }//end testIndexForwardsFilters()


    /**
     * verify returns the service result verbatim.
     *
     * @return void
     */
    public function testVerifyReturnsServiceResult(): void
    {
        $service = $this->createMock(AuditLogService::class);
        $service->method('verify')->willReturn(['valid' => true, 'checked' => 5, 'tampered' => []]);

        $controller = $this->makeController($service);
        $response   = $controller->verify('e1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['valid']);

    }//end testVerifyReturnsServiceResult()


    /**
     * export returns a DataDisplayResponse on success.
     *
     * @return void
     */
    public function testExportReturnsDataDownload(): void
    {
        $service = $this->createMock(AuditLogService::class);
        $service->method('export')->willReturn(
            [
                'success' => true,
                'format'  => 'csv',
                'body'    => 'id,timestamp\nrow-0,2026',
                'count'   => 1,
            ]
        );

        $controller = $this->makeController($service, requestParams: ['format' => 'csv']);
        $response   = $controller->export();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDisplayResponse::class, $response);

    }//end testExportReturnsDataDownload()


    /**
     * export rejects unknown formats with 422.
     *
     * @return void
     */
    public function testExportRejectsBadFormat(): void
    {
        $service = $this->createMock(AuditLogService::class);
        $service->method('export')->willReturn(
            [
                'success' => false,
                'format'  => 'xml',
                'body'    => '',
                'count'   => 0,
            ]
        );

        $controller = $this->makeController($service, requestParams: ['format' => 'xml']);
        $response   = $controller->export();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testExportRejectsBadFormat()


}//end class
