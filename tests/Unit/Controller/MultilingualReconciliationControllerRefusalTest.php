<?php

/**
 * Unit tests for MultilingualReconciliationController's refusal paths.
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
 * @spec openspec/specs/authorization-via-or-rbac/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\GovernanceControllerTrait;
use OCA\Decidiq\Controller\MultilingualReconciliationController;
use OCA\Decidiq\Controller\RequiresOrAdmin;
use OCA\Decidiq\Kernel\Contracts\MultilingualReconciliationServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The admin guard on status()/process() and a refused queue().
 */
#[CoversClass(MultilingualReconciliationController::class)]
#[UsesClass(RequiresOrAdmin::class)]
#[UsesClass(GovernanceControllerTrait::class)]
final class MultilingualReconciliationControllerRefusalTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock reconciler.
	 *
	 * @var MultilingualReconciliationServiceInterface&MockObject
	 */
	private MultilingualReconciliationServiceInterface&MockObject $reconciler;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request      = $this->createMock(IRequest::class);
		$this->reconciler   = $this->createMock(MultilingualReconciliationServiceInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * Build the controller for a caller, or for no session when $uid is null.
	 *
	 * @param string|null $uid The caller's UID.
	 *
	 * @return MultilingualReconciliationController
	 */
	private function controllerFor(?string $uid): MultilingualReconciliationController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new MultilingualReconciliationController(
			request: $this->request,
			reconciler: $this->reconciler,
			userSession: $session,
			groupManager: $this->groupManager,
		);

	}//end controllerFor()

	/**
	 * A non-admin is refused the queue status with 403.
	 *
	 * @return void
	 */
	public function testStatusRefusesANonAdmin(): void {
		$this->groupManager->expects($this->once())->method('isAdmin')->with('bob')->willReturn(false);
		$this->reconciler->expects($this->never())->method('status');

		$response = $this->controllerFor('bob')->status();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Administrator role required.'], $response->getData());

	}//end testStatusRefusesANonAdmin()

	/**
	 * Without a session, force-processing is refused with 401.
	 *
	 * @return void
	 */
	public function testProcessRefusesAnUnauthenticatedCaller(): void {
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->reconciler->expects($this->never())->method('processQueue');

		$response = $this->controllerFor(null)->process();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Authentication required.'], $response->getData());

	}//end testProcessRefusesAnUnauthenticatedCaller()

	/**
	 * A queue request the reconciler refuses answers 422 with its message.
	 *
	 * @return void
	 */
	public function testARefusedQueueRequestAnswersUnprocessable(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => match ($key) {
				'minutesId' => 'min-1',
				'sourceLocale' => 'nl',
				'targetLocales' => ['en', 'fy'],
				default => $default,
			}
		);
		$this->reconciler->expects($this->once())
			->method('queue')
			->with('min-1', 'nl', ['en', 'fy'])
			->willReturn(['success' => false, 'message' => 'Minutes record not published.']);

		$response = $this->controllerFor('admin')->queue();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'Minutes record not published.'], $response->getData());

	}//end testARefusedQueueRequestAnswersUnprocessable()
}//end class
