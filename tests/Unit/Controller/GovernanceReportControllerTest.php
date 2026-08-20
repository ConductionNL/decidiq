<?php

/**
 * Unit tests for GovernanceReportController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\GovernanceReportController;
use OCA\Decidesk\Service\GovernanceReportingService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GovernanceReportController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
 */
class GovernanceReportControllerTest extends TestCase {

	/**
	 * Build a controller wired to the supplied service.
	 *
	 * @param GovernanceReportingService $service Service double
	 * @param array<string, mixed> $requestParams Params returned by IRequest
	 * @param bool $authenticated Whether session has a user
	 * @param bool $admin Whether the user is admin
	 *
	 * @return GovernanceReportController
	 */
	private function makeController(GovernanceReportingService $service, array $requestParams = [], bool $authenticated = true, bool $admin = true): GovernanceReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($requestParams);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($requestParams): mixed {
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

		return new GovernanceReportController($request, $service, $session, $groups);
	}//end makeController()

	/**
	 * generate requires authentication.
	 *
	 * @return void
	 */
	public function testGenerateRequiresAuth(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$controller = $this->makeController($service, authenticated: false);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGenerateRequiresAuth()

	/**
	 * generate requires admin.
	 *
	 * @return void
	 */
	public function testGenerateRequiresAdmin(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$controller = $this->makeController($service, admin: false);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testGenerateRequiresAdmin()

	/**
	 * generate rejects missing fields.
	 *
	 * @return void
	 */
	public function testGenerateRejectsMissingFields(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$controller = $this->makeController($service);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testGenerateRejectsMissingFields()

	/**
	 * generate returns 201 on success.
	 *
	 * @return void
	 */
	public function testGenerateReturns201(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$service->expects($this->once())->method('generateAnnualReport')
			->with('b-1', 2026)
			->willReturn(['success' => true, 'report' => ['id' => 'rep-1'], 'message' => 'ok']);

		$controller = $this->makeController($service, requestParams: ['boardId' => 'b-1', 'year' => 2026]);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());

	}//end testGenerateReturns201()

	/**
	 * index requires boardId.
	 *
	 * @return void
	 */
	public function testIndexRequiresBoardId(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$controller = $this->makeController($service);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testIndexRequiresBoardId()

	/**
	 * show returns the report JSON.
	 *
	 * @return void
	 */
	public function testShowReturnsReportJson(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$service->method('exportReport')->willReturn(
			[
				'success' => true,
				'body' => json_encode(['boardIntegration' => 'b-1', 'year' => 2026]),
				'contentType' => 'application/json',
				'message' => 'ok',
			]
		);

		$controller = $this->makeController($service);
		$response = $controller->show('rep-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('b-1', $response->getData()['boardIntegration']);

	}//end testShowReturnsReportJson()

	/**
	 * show returns 404 when service reports not-found.
	 *
	 * @return void
	 */
	public function testShowReturns404OnNotFound(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$service->method('exportReport')->willReturn(
			[
				'success' => false,
				'body' => '',
				'contentType' => 'text/plain',
				'message' => 'Report not found.',
			]
		);

		$controller = $this->makeController($service);
		$response = $controller->show('rep-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testShowReturns404OnNotFound()

	/**
	 * export delegates to service and returns a DataDisplayResponse on success.
	 *
	 * @return void
	 */
	public function testExportReturnsCsvDownload(): void {
		$service = $this->createMock(GovernanceReportingService::class);
		$service->expects($this->once())->method('exportReport')
			->with('rep-1', 'csv')
			->willReturn(
				[
					'success' => true,
					'body' => 'key,value',
					'contentType' => 'text/csv',
					'message' => 'ok',
				]
			);

		$controller = $this->makeController($service);
		$response = $controller->export('rep-1', 'csv');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Response::getHeaders() requires OC::$server (real bootstrap); status is enough here.

	}//end testExportReturnsCsvDownload()

}//end class
