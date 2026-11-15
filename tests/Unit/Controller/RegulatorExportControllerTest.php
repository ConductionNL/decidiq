<?php

/**
 * Unit tests for RegulatorExportController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\RegulatorExportController;
use OCA\Decidiq\Service\RegulatorExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegulatorExportController.
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
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

	/**
	 * download streams the stored export as an attachment for the caller.
	 *
	 * @return void
	 */
	public function testDownloadReturnsTheAttachment(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->expects($this->once())->method('download')
			->with('e-1', 'alice')
			->willReturn([
				'success' => true,
				'body' => 'id,title',
				'contentType' => 'text/csv',
				'filename' => 'export-e-1.csv',
				'export' => ['id' => 'e-1'],
				'message' => 'ok',
			]);

		$response = $this->makeController($service)->download('e-1');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('id,title', $response->getData());
		// getHeaders() requires OC::$server; read the headers the controller set.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('text/csv', $headers['Content-Type']);
		$this->assertSame('attachment; filename="export-e-1.csv"', $headers['Content-Disposition']);

	}//end testDownloadReturnsTheAttachment()

	/**
	 * download answers 422 for a failure that is not a missing export.
	 *
	 * @return void
	 */
	public function testDownloadReturns422OnOtherFailures(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->method('download')->willReturn(['success' => false, 'message' => 'The export file is unreadable.']);

		$response = $this->makeController($service)->download('e-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'The export file is unreadable.'], $response->getData());

	}//end testDownloadReturns422OnOtherFailures()

	/**
	 * download and index refuse a non-admin before the service is asked.
	 *
	 * @return void
	 */
	public function testDownloadAndIndexRequireAdmin(): void {
		$service = $this->createMock(RegulatorExportService::class);
		$service->expects($this->never())->method('download');
		$service->expects($this->never())->method('listExports');
		$controller = $this->makeController($service, requestParams: ['boardId' => 'b-1'], isAdmin: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->download('e-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->index()->getStatus());

	}//end testDownloadAndIndexRequireAdmin()

}//end class
