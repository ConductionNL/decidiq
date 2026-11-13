<?php

/**
 * Unit tests for GovernanceReportController: the list, the refusals and the failed exports.
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
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\GovernanceReportController;
use OCA\Decidiq\Service\GovernanceReportingService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The governance report list, every admin refusal, and the export failures.
 *
 * @covers \OCA\Decidiq\Controller\GovernanceReportController
 */
class GovernanceReportControllerRefusalTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var GovernanceReportingService&MockObject
	 */
	private GovernanceReportingService&MockObject $service;

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(GovernanceReportingService::class);
	}//end setUp()

	/**
	 * A controller for a signed-in caller.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $admin  Whether the caller is an administrator.
	 *
	 * @return GovernanceReportController The controller.
	 */
	private function controller(array $params = [], bool $admin = true): GovernanceReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('bob')->willReturn($admin);

		return new GovernanceReportController($request, $this->service, $session, $groups);
	}//end controller()

	/**
	 * The list answers the board's reports and their count.
	 *
	 * @return void
	 */
	public function testIndexListsTheBoardsReports(): void {
		$this->service->expects($this->once())->method('listReports')->with('b-1')
			->willReturn(['reports' => [['id' => 'r-1'], ['id' => 'r-2']], 'count' => 2]);

		$response = $this->controller(params: ['boardId' => 'b-1'])->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'r-1'], ['id' => 'r-2']], 'total' => 2], $response->getData());
	}//end testIndexListsTheBoardsReports()

	/**
	 * A caller who is not an administrator is refused by list, show and export alike, before the service is asked.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefusedEverywhere(): void {
		$this->service->expects($this->never())->method('listReports');
		$this->service->expects($this->never())->method('exportReport');
		$controller = $this->controller(params: ['boardId' => 'b-1'], admin: false);

		foreach ([$controller->index(), $controller->show('r-1'), $controller->export('r-1', 'csv')] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => 'Administrator role required.'], $response->getData());
		}
	}//end testANonAdminIsRefusedEverywhere()

	/**
	 * A failure that is not a missing report is a 422 with the service's message.
	 *
	 * @return void
	 */
	public function testShowAnswers422ForAnotherFailure(): void {
		$this->service->method('exportReport')->with('r-1', 'json')
			->willReturn(['success' => false, 'body' => '', 'contentType' => 'text/plain', 'message' => 'Unsupported format.']);

		$response = $this->controller()->show('r-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Unsupported format.'], $response->getData());
	}//end testShowAnswers422ForAnotherFailure()

	/**
	 * A body that is not a JSON object is answered wrapped, not dropped.
	 *
	 * @return void
	 */
	public function testShowWrapsABodyThatIsNotAJsonObject(): void {
		$this->service->method('exportReport')
			->willReturn(['success' => true, 'body' => 'plain text', 'contentType' => 'text/plain', 'message' => 'ok']);

		$response = $this->controller()->show('r-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['body' => 'plain text'], $response->getData());
	}//end testShowWrapsABodyThatIsNotAJsonObject()

	/**
	 * A failed export is a 422 with the service's message, not a download.
	 *
	 * @return void
	 */
	public function testAFailedExportIs422(): void {
		$this->service->method('exportReport')->with('r-1', 'xml')
			->willReturn(['success' => false, 'body' => '', 'contentType' => 'text/plain', 'message' => 'Unsupported format: xml.']);

		$response = $this->controller()->export('r-1', 'xml');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Unsupported format: xml.'], $response->getData());
	}//end testAFailedExportIs422()
}//end class
