<?php

/**
 * Wire-contract tests for the action-item VTODO delete endpoint.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Controller\ActionItemController;
use OCA\Decidesk\Service\ActionItemWriter;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `DELETE /api/action-items/{uid}` (actionItem#destroy).
 *
 * The `action-item` schema is a read-only OpenRegister projection over CalDAV
 * VTODOs, so this endpoint is the ONLY delete path the frontend has. Three wire
 * outcomes have to stay distinguishable: 401 with no session, 404 for a uid the
 * acting user does not own, and 200 on a real delete. Collapsing the 404 into a
 * 200 would make the UI report a successful delete that never happened.
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */
class ActionItemControllerTest extends TestCase {

	/**
	 * Mock ActionItemWriter.
	 *
	 * @var ActionItemWriter&MockObject
	 */
	private ActionItemWriter&MockObject $writer;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ActionItemController
	 */
	private ActionItemController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->writer = $this->createMock(ActionItemWriter::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new ActionItemController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$this->writer,
			$this->userSession,
		);

	}//end setUp()

	/**
	 * Sign a user into the mocked session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * An anonymous caller gets 401 and the writer is never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function testDestroyWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->writer->expects($this->never())->method('delete');

		$response = $this->controller->destroy(uid: 'todo-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Not authenticated', $response->getData()['error']);

	}//end testDestroyWithoutSessionIs401()

	/**
	 * A uid the writer cannot resolve among the acting user's own tasks is 404,
	 * NOT a silent success. The writer scopes its lookup to the caller's own
	 * calendar, so "not found" is also how another user's VTODO presents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function testDestroyUnknownUidIs404(): void {
		$this->signIn();
		$this->writer->method('delete')->with(uid: 'someone-elses-todo')->willReturn(false);

		$response = $this->controller->destroy(uid: 'someone-elses-todo');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Action item not found', $response->getData()['error']);
		self::assertArrayNotHasKey('success', $response->getData());

	}//end testDestroyUnknownUidIs404()

	/**
	 * A successful delete answers 200 with `{ success: true }` and hands the
	 * uid through to the writer unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function testDestroyDeletesAndReturnsSuccess(): void {
		$this->signIn();
		$this->writer->expects($this->once())
			->method('delete')
			->with(uid: 'todo-42')
			->willReturn(true);

		$response = $this->controller->destroy(uid: 'todo-42');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['success']);

	}//end testDestroyDeletesAndReturnsSuccess()

}//end class
