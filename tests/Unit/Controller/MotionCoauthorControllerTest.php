<?php

/**
 * Wire-contract tests for the motion co-authoring endpoints.
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

use OCA\Decidesk\Controller\MotionCoauthorController;
use OCA\Decidesk\Service\MotionCoauthorService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the four co-authoring routes:
 *
 *   POST   /api/motions/{id}/coauthors
 *   DELETE /api/motions/{id}/coauthors/{personId}
 *   POST   /api/motions/{id}/text
 *   GET    /api/motions/{id}/history
 *
 * Every one of them is `@NoAdminRequired`, so the wire contract that matters is
 * the status mapping, not the happy path: an ownership rejection has to arrive
 * as 403, a missing motion as 404, and — the one that is easy to get wrong — a
 * concurrent-edit conflict on `updateText` as 409 rather than the 404 the same
 * `RuntimeException` produces on the coauthor routes. A client that cannot tell
 * "someone else edited this" from "this motion is gone" silently discards the
 * user's text.
 *
 * The caller-identity forwarding is asserted too: an admin must reach the
 * service with a NULL caller uid (ownership bypass) and a plain member with
 * their own uid. Passing the admin's uid straight through would make the admin
 * bypass silently stop working while every status code stayed correct.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
 */
class MotionCoauthorControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock MotionCoauthorService.
	 *
	 * @var MotionCoauthorService&MockObject
	 */
	private MotionCoauthorService&MockObject $coauthorService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var MotionCoauthorController
	 */
	private MotionCoauthorController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->coauthorService = $this->createMock(MotionCoauthorService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new MotionCoauthorController(
			$this->request,
			$this->coauthorService,
			$this->userSession,
			$this->groupManager,
		);

	}//end setUp()

	/**
	 * Sign a user into the mocked session.
	 *
	 * @param string $uid The Nextcloud uid.
	 * @param bool $isAdmin Whether the uid is an instance admin.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'raadslid', bool $isAdmin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);

	}//end signIn()

	/**
	 * An anonymous caller gets 401 on addCoauthor and the service is untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testAddCoauthorWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->coauthorService->expects($this->never())->method('addCoauthor');

		$response = $this->controller->addCoauthor(id: 'motion-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAddCoauthorWithoutSessionIs401()

	/**
	 * A body without `personId` is rejected 422 before the service runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testAddCoauthorWithoutPersonIdIs422(): void {
		$this->signIn();
		$this->request->method('getParam')->with('personId', '')->willReturn('');
		$this->coauthorService->expects($this->never())->method('addCoauthor');

		$response = $this->controller->addCoauthor(id: 'motion-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('personId', $response->getData()['message']);

	}//end testAddCoauthorWithoutPersonIdIs422()

	/**
	 * A member adding a coauthor reaches the service with their OWN uid as the
	 * access check, and the updated motion comes back as the body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testAddCoauthorForwardsCallerUidAndReturnsMotion(): void {
		$this->signIn(uid: 'raadslid');
		$this->request->method('getParam')->with('personId', '')->willReturn('person-7');

		$this->coauthorService->expects($this->once())
			->method('addCoauthor')
			->with(motionId: 'motion-1', personId: 'person-7', callerUid: 'raadslid')
			->willReturn(['id' => 'motion-1', 'coauthors' => ['person-7']]);

		$response = $this->controller->addCoauthor(id: 'motion-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['person-7'], $response->getData()['coauthors']);

	}//end testAddCoauthorForwardsCallerUidAndReturnsMotion()

	/**
	 * An instance admin reaches the service with a NULL caller uid, which is
	 * how the service is told to skip the ownership check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testAddCoauthorAsAdminBypassesOwnership(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->request->method('getParam')->with('personId', '')->willReturn('person-7');

		$this->coauthorService->expects($this->once())
			->method('addCoauthor')
			->with(motionId: 'motion-1', personId: 'person-7', callerUid: null)
			->willReturn(['id' => 'motion-1']);

		self::assertSame(Http::STATUS_OK, $this->controller->addCoauthor(id: 'motion-1')->getStatus());

	}//end testAddCoauthorAsAdminBypassesOwnership()

	/**
	 * A non-owner is rejected 403 (not 404): the motion exists, the caller may
	 * not change its authorship.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testAddCoauthorNonOwnerIs403(): void {
		$this->signIn();
		$this->request->method('getParam')->with('personId', '')->willReturn('person-7');
		$this->coauthorService->method('addCoauthor')
			->willThrowException(new \InvalidArgumentException('Only the motion author may add coauthors.'));

		$response = $this->controller->addCoauthor(id: 'motion-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAddCoauthorNonOwnerIs403()

	/**
	 * removeCoauthor returns the updated motion and forwards both ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testRemoveCoauthorReturnsUpdatedMotion(): void {
		$this->signIn(uid: 'raadslid');

		$this->coauthorService->expects($this->once())
			->method('removeCoauthor')
			->with(motionId: 'motion-1', personId: 'person-7', callerUid: 'raadslid')
			->willReturn(['id' => 'motion-1', 'coauthors' => []]);

		$response = $this->controller->removeCoauthor(id: 'motion-1', personId: 'person-7');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData()['coauthors']);

	}//end testRemoveCoauthorReturnsUpdatedMotion()

	/**
	 * An unknown motion on removeCoauthor is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testRemoveCoauthorUnknownMotionIs404(): void {
		$this->signIn();
		$this->coauthorService->method('removeCoauthor')
			->willThrowException(new \RuntimeException('Motion not found.'));

		$response = $this->controller->removeCoauthor(id: 'ghost', personId: 'person-7');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRemoveCoauthorUnknownMotionIs404()

	/**
	 * An empty `text` body is 422 before the service runs — an empty motion
	 * text would otherwise be captured as a version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testUpdateTextWithEmptyBodyIs422(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturn('');
		$this->coauthorService->expects($this->never())->method('updateMotionText');

		$response = $this->controller->updateText(id: 'motion-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testUpdateTextWithEmptyBodyIs422()

	/**
	 * updateText forwards text, author and change summary, and returns the
	 * updated motion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testUpdateTextForwardsBodyAndReturnsMotion(): void {
		$this->signIn(uid: 'raadslid');
		$this->request->method('getParam')->willReturnMap(
			[
				['text', '', 'De raad besluit anders.'],
				['summary', '', 'Herformulering dictum'],
			]
		);

		$this->coauthorService->expects($this->once())
			->method('updateMotionText')
			->with(
				motionId: 'motion-1',
				newText: 'De raad besluit anders.',
				author: 'raadslid',
				changeSummary: 'Herformulering dictum',
				callerUid: 'raadslid'
			)
			->willReturn(['id' => 'motion-1', 'text' => 'De raad besluit anders.']);

		$response = $this->controller->updateText(id: 'motion-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('De raad besluit anders.', $response->getData()['text']);

	}//end testUpdateTextForwardsBodyAndReturnsMotion()

	/**
	 * A concurrent-edit rejection on updateText is 409 — NOT the 404 the same
	 * RuntimeException maps to on the coauthor routes. The client needs the two
	 * apart to decide between "reload and merge" and "this motion is gone".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testUpdateTextConflictIs409(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturnMap(
			[
				['text', '', 'Nieuwe tekst'],
				['summary', '', ''],
			]
		);
		$this->coauthorService->method('updateMotionText')
			->willThrowException(new \RuntimeException('Motion text changed since it was loaded.'));

		$response = $this->controller->updateText(id: 'motion-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testUpdateTextConflictIs409()

	/**
	 * history() returns the version list under a `history` key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testHistoryReturnsVersionList(): void {
		$this->signIn();
		$versions = [
			['version' => 2, 'author' => 'raadslid', 'changeSummary' => 'Herformulering dictum'],
			['version' => 1, 'author' => 'raadslid', 'changeSummary' => 'Eerste versie'],
		];
		$this->coauthorService->method('getHistory')->with('motion-1')->willReturn($versions);

		$response = $this->controller->history(id: 'motion-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($versions, $response->getData()['history']);

	}//end testHistoryReturnsVersionList()

	/**
	 * history() on an unknown motion is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
	 */
	public function testHistoryUnknownMotionIs404(): void {
		$this->signIn();
		$this->coauthorService->method('getHistory')
			->willThrowException(new \RuntimeException('Motion not found.'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->history(id: 'ghost')->getStatus()
		);

	}//end testHistoryUnknownMotionIs404()

}//end class
