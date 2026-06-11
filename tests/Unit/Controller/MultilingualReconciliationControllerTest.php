<?php
/**
 * Unit tests for MultilingualReconciliationController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MultilingualReconciliationController;
use OCA\Decidesk\Service\MultilingualReconciliationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MultilingualReconciliationController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationControllerTest extends TestCase
{


    /**
     * Build a controller wired to the supplied service and request.
     *
     * @param MultilingualReconciliationService $service       Service double
     * @param array<string, mixed>              $requestParams Params returned by IRequest
     * @param bool                              $authenticated Whether session has a user
     * @param bool                              $isAdmin       Whether user is admin
     *
     * @return MultilingualReconciliationController
     */
    private function makeController(
        MultilingualReconciliationService $service,
        array $requestParams=[],
        bool $authenticated=true,
        bool $isAdmin=true,
    ): MultilingualReconciliationController {
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
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new MultilingualReconciliationController($request, $service, $session, $groupManager);

    }//end makeController()


    /**
     * queue without auth returns 401.
     *
     * @return void
     */
    public function testQueueRequiresAuth(): void
    {
        $service    = $this->createMock(MultilingualReconciliationService::class);
        $controller = $this->makeController($service, authenticated: false);

        $response = $controller->queue();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testQueueRequiresAuth()


    /**
     * queue without admin returns 403.
     *
     * @return void
     */
    public function testQueueRequiresAdmin(): void
    {
        $service    = $this->createMock(MultilingualReconciliationService::class);
        $controller = $this->makeController($service, isAdmin: false);

        $response = $controller->queue();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testQueueRequiresAdmin()


    /**
     * queue rejects missing params.
     *
     * @return void
     */
    public function testQueueRejectsMissingParams(): void
    {
        $service    = $this->createMock(MultilingualReconciliationService::class);
        $controller = $this->makeController($service);

        $response = $controller->queue();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testQueueRejectsMissingParams()


    /**
     * queue returns 201 + total on success.
     *
     * @return void
     */
    public function testQueueReturns201OnSuccess(): void
    {
        $service = $this->createMock(MultilingualReconciliationService::class);
        $service->expects($this->once())->method('queue')
            ->with('min-1', 'nl', ['en'])
            ->willReturn([
                'success' => true,
                'entries' => [['id' => 'q-1', 'targetLocale' => 'en']],
                'message' => 'ok',
            ]);

        $controller = $this->makeController(
            $service,
            requestParams: ['minutesId' => 'min-1', 'sourceLocale' => 'nl', 'targetLocales' => ['en']]
        );

        $response = $controller->queue();
        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['total']);

    }//end testQueueReturns201OnSuccess()


    /**
     * status returns summary + results.
     *
     * @return void
     */
    public function testStatusReturnsSummary(): void
    {
        $service = $this->createMock(MultilingualReconciliationService::class);
        $service->expects($this->once())->method('status')
            ->with(50)
            ->willReturn([
                'success' => true,
                'summary' => ['queued' => 2, 'completed' => 1, 'failed' => 0, 'processing' => 0],
                'entries' => [['id' => 'q-1'], ['id' => 'q-2']],
                'message' => 'ok',
            ]);

        $controller = $this->makeController($service);
        $response   = $controller->status();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame(2, $payload['summary']['queued']);
        $this->assertCount(2, $payload['results']);

    }//end testStatusReturnsSummary()


    /**
     * process delegates with the supplied maxEntries.
     *
     * @return void
     */
    public function testProcessDelegates(): void
    {
        $service = $this->createMock(MultilingualReconciliationService::class);
        $service->expects($this->once())->method('processQueue')
            ->with(5)
            ->willReturn([
                'success'   => true,
                'processed' => 5,
                'completed' => 4,
                'failed'    => 1,
                'message'   => 'Processed 5 entries (4 completed, 1 failed).',
            ]);

        $controller = $this->makeController(
            $service,
            requestParams: ['maxEntries' => 5]
        );

        $response = $controller->process();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame(5, $payload['processed']);
        $this->assertSame(4, $payload['completed']);

    }//end testProcessDelegates()


}//end class
