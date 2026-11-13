<?php

/**
 * Wire-contract tests for the action-item VTODO create and update endpoints.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
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

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Controller\ActionItemController;
use OCA\Decidiq\Service\ActionItemWriter;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `POST /api/action-items` and `PUT /api/action-items/{uid}`.
 *
 * @covers \OCA\Decidiq\Controller\ActionItemController
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */
class ActionItemControllerWriteTest extends TestCase {

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
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

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
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new ActionItemController(
			Application::APP_ID,
			$this->request,
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
	 * An anonymous create is 401 and never reaches the writer.
	 *
	 * @return void
	 */
	public function testCreateWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->writer->expects($this->never())->method('create');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testCreateWithoutSessionIs401()

	/**
	 * Create strips the `_route` param and answers 201 with the created item.
	 *
	 * @return void
	 */
	public function testCreateStripsRouteAndReturns201(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(
			['_route' => 'decidiq.actionItem.create', 'title' => 'Notulen rondsturen', 'dueDate' => '2026-12-01']
		);
		$created = ['uid' => 'todo-1', 'title' => 'Notulen rondsturen'];
		$this->writer->expects($this->once())
			->method('create')
			->with(item: ['title' => 'Notulen rondsturen', 'dueDate' => '2026-12-01'])
			->willReturn($created);

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['success' => true, 'actionItem' => $created], $response->getData());

	}//end testCreateStripsRouteAndReturns201()

	/**
	 * A failed create is 500, not a silent success.
	 *
	 * @return void
	 */
	public function testCreateFailureIs500(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['title' => 'X']);
		$this->writer->method('create')->willReturn(null);

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not create action item'], $response->getData());

	}//end testCreateFailureIs500()

	/**
	 * An anonymous update is 401 and never reaches the writer.
	 *
	 * @return void
	 */
	public function testUpdateWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->writer->expects($this->never())->method('update');

		$response = $this->controller->update(uid: 'todo-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testUpdateWithoutSessionIs401()

	/**
	 * Update strips `_route` and `uid` from the changes and answers 200.
	 *
	 * @return void
	 */
	public function testUpdateStripsRoutingParamsAndReturnsItem(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(
			['_route' => 'decidiq.actionItem.update', 'uid' => 'todo-1', 'status' => 'completed']
		);
		$updated = ['uid' => 'todo-1', 'status' => 'completed'];
		$this->writer->expects($this->once())
			->method('update')
			->with(uid: 'todo-1', changes: ['status' => 'completed'])
			->willReturn($updated);

		$response = $this->controller->update(uid: 'todo-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true, 'actionItem' => $updated], $response->getData());

	}//end testUpdateStripsRoutingParamsAndReturnsItem()

	/**
	 * An unresolvable uid on update is 404.
	 *
	 * @return void
	 */
	public function testUpdateUnknownUidIs404(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['status' => 'completed']);
		$this->writer->method('update')->willReturn(null);

		$response = $this->controller->update(uid: 'someone-elses-todo');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Action item not found'], $response->getData());

	}//end testUpdateUnknownUidIs404()

	/**
	 * An anonymous delete is 401 and never reaches the writer.
	 *
	 * @return void
	 */
	public function testDestroyWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->writer->expects($this->never())->method('delete');

		$response = $this->controller->destroy(uid: 'todo-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testDestroyWithoutSessionIs401()

}//end class
