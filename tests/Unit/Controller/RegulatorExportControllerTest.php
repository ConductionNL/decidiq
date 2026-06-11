<?php
/**
 * Unit tests for RegulatorExportController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\RegulatorExportController;
use OCA\Decidesk\Service\RegulatorExportService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegulatorExportController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportControllerTest extends TestCase
{


    /**
     * Build a controller wired to the supplied service double.
     *
     * @param RegulatorExportService $service       Service double
     * @param array<string, mixed>   $requestParams Params returned by IRequest
     * @param bool                   $authenticated Whether session has a user
     * @param bool                   $admin         Whether the user is admin
     *
     * @return RegulatorExportController
     */
    private function makeController(RegulatorExportService $service, array $requestParams=[], bool $authenticated=true, bool $admin=true): RegulatorExportController
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

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);

        return new RegulatorExportController($request, $service, $session, $groups);

    }//end makeController()


    /**
     * generate requires authentication.
     *
     * @return void
     */
    public function testGenerateRequiresAuth(): void
    {
        $service    = $this->createMock(RegulatorExportService::class);
        $controller = $this->makeController($service, authenticated: false);

        $response = $controller->generate();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGenerateRequiresAuth()


    /**
     * generate requires admin.
     *
     * @return void
     */
    public function testGenerateRequiresAdmin(): void
    {
        $service    = $this->createMock(RegulatorExportService::class);
        $controller = $this->makeController($service, admin: false);

        $response = $controller->generate();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testGenerateRequiresAdmin()


    /**
     * generate rejects missing fields.
     *
     * @return void
     */
    public function testGenerateRejectsMissingFields(): void
    {
        $service    = $this->createMock(RegulatorExportService::class);
        $controller = $this->makeController($service, requestParams: ['boardId' => 'b-1']);

        $response = $controller->generate();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testGenerateRejectsMissingFields()


    /**
     * generate returns 201 + export metadata on success.
     *
     * @return void
     */
    public function testGenerateReturnsCreatedOnSuccess(): void
    {
        $service = $this->createMock(RegulatorExportService::class);
        $service->expects($this->once())->method('generate')
            ->willReturn(
                [
                    'success'     => true,
                    'export'      => ['id' => 'exp-1'],
                    'body'        => 'csv-body',
                    'contentType' => 'text/csv',
                    'message'     => 'ok',
                ]
            );

        $controller = $this->makeController(
            $service,
            requestParams: [
                'boardId'   => 'b-1',
                'template'  => 'dnb-resolutions-quarterly',
                'startDate' => '2026-01-01T00:00:00Z',
                'endDate'   => '2026-12-31T23:59:59Z',
                'format'    => 'csv',
            ]
        );

        $response = $controller->generate();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('text/csv', $response->getData()['contentType']);

    }//end testGenerateReturnsCreatedOnSuccess()


    /**
     * generate returns 422 on service failure.
     *
     * @return void
     */
    public function testGenerateReturns422OnServiceFailure(): void
    {
        $service = $this->createMock(RegulatorExportService::class);
        $service->method('generate')->willReturn(
            [
                'success'     => false,
                'export'      => null,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Unknown regulator template: bogus',
            ]
        );

        $controller = $this->makeController(
            $service,
            requestParams: [
                'boardId'   => 'b-1',
                'template'  => 'bogus',
                'startDate' => 's',
                'endDate'   => 'e',
            ]
        );

        $response = $controller->generate();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testGenerateReturns422OnServiceFailure()


    /**
     * download returns a DataDisplayResponse with the persisted CSV body.
     *
     * @return void
     */
    public function testDownloadReturnsCsv(): void
    {
        $service = $this->createMock(RegulatorExportService::class);
        $service->expects($this->once())->method('download')
            ->with('exp-1')
            ->willReturn(
                [
                    'success'     => true,
                    'body'        => 'csv-body',
                    'contentType' => 'text/csv',
                    'message'     => 'ok',
                ]
            );

        $controller = $this->makeController($service);
        $response   = $controller->download('exp-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('text/csv', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('regulator-export-exp-1.csv', $response->getHeaders()['Content-Disposition']);

    }//end testDownloadReturnsCsv()


    /**
     * download returns 404 when service reports not found.
     *
     * @return void
     */
    public function testDownloadReturns404OnNotFound(): void
    {
        $service = $this->createMock(RegulatorExportService::class);
        $service->method('download')->willReturn(
            [
                'success'     => false,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Export not found.',
            ]
        );

        $controller = $this->makeController($service);
        $response   = $controller->download('missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testDownloadReturns404OnNotFound()


}//end class
