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
class RegulatorExportControllerTest extends TestCase {

	/**
	 * Build a controller wired to the supplied service and request.
	 *
	 * @param RegulatorExportService $service Service double
	 * @param array<string, mixed> $requestParams Params returned by IRequest
	 * @param bool $authenticated Whether session has a user
	 * @param bool $isAdmin Whether user is admin
	 *
	 * @return RegulatorExportController
	 */
	private function makeController(
		RegulatorExportService $service,
		array $requestParams = [],
		bool $authenticated = true,
		bool $isAdmin = true,
	): RegulatorExportController {
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

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new RegulatorExportController($request, $service, $session, $groupManager);
	}//end makeController()

	/**
	 * generate without auth returns 401.
	 *
	 * @return void
	 */
	public function testGenerateRequiresAuthentication(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$controller = $this->makeController($service, authenticated: false);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGenerateRequiresAuthentication()

	/**
	 * generate requires admin.
	 *
	 * @return void
	 */
	public function testGenerateRequiresAdmin(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$controller = $this->makeController($service, isAdmin: false);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testGenerateRequiresAdmin()

	/**
	 * generate rejects missing boardId.
	 *
	 * @return void
	 */
	public function testGenerateRejectsMissingBoardId(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$controller = $this->makeController($service);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testGenerateRejectsMissingBoardId()

	/**
	 * generate returns 422 when the service reports failure.
	 *
	 * @return void
	 */
	public function testGenerateReturns422OnServiceFailure(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->expects($this->once())->method('generate')
			->willReturn([
				'success' => false,
				'export' => null,
				'body' => '',
				'contentType' => 'text/plain',
				'filename' => '',
				'message' => 'Unsupported scope: foo',
			]);

		$controller = $this->makeController(
			$service,
			requestParams: ['boardId' => 'b-1', 'scope' => 'foo']
		);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testGenerateReturns422OnServiceFailure()

	/**
	 * generate delegates and returns export blob with attachment header.
	 *
	 * @return void
	 */
	public function testGenerateReturnsAttachment(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->expects($this->once())->method('generate')
			->with('b-1', 'resolutions', 'csv', 'alice')
			->willReturn([
				'success' => true,
				'export' => ['id' => 'e-1', 'sha256' => 'abc'],
				'body' => 'id,title',
				'contentType' => 'text/csv',
				'filename' => 'test.csv',
				'message' => 'ok',
			]);

		$controller = $this->makeController(
			$service,
			requestParams: ['boardId' => 'b-1', 'scope' => 'resolutions', 'format' => 'csv']
		);

		$response = $controller->generate();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// getHeaders() requires OC::$server (real Nextcloud bootstrap); status is enough here.

	}//end testGenerateReturnsAttachment()

	/**
	 * index requires boardId.
	 *
	 * @return void
	 */
	public function testIndexRequiresBoardId(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$controller = $this->makeController($service);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testIndexRequiresBoardId()

	/**
	 * index returns results envelope.
	 *
	 * @return void
	 */
	public function testIndexReturnsResults(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->expects($this->once())->method('listExports')
			->with('b-1')
			->willReturn(['success' => true, 'exports' => [['id' => 'e-1']], 'count' => 1]);

		$controller = $this->makeController($service, requestParams: ['boardId' => 'b-1']);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$payload = $response->getData();
		$this->assertSame(1, $payload['total']);

	}//end testIndexReturnsResults()

	/**
	 * download returns 404 for missing exports.
	 *
	 * @return void
	 */
	public function testDownloadReturns404OnMissing(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->expects($this->once())->method('download')
			->willReturn([
				'success' => false,
				'body' => '',
				'contentType' => 'text/plain',
				'filename' => '',
				'export' => null,
				'message' => 'Export not found.',
			]);

		$controller = $this->makeController($service);

		$response = $controller->download('missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testDownloadReturns404OnMissing()

}//end class
