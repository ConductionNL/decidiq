<?php

/**
 * Unit tests for OnboardingController::updateStep() edge paths.
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
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\OnboardingController;
use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\Decidiq\Kernel\Contracts\GovernanceScopeGuardInterface;
use OCA\Decidiq\Service\OnboardingChecklistService;
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
 * No session, a refused step and a non-string outcome.
 */
#[CoversClass(OnboardingController::class)]
#[UsesClass(OnboardingChecklistService::class)]
#[UsesClass(OnboardingStepRefusedException::class)]
final class OnboardingControllerStepTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock scope guard.
	 *
	 * @var GovernanceScopeGuardInterface&MockObject
	 */
	private GovernanceScopeGuardInterface&MockObject $scopeGuard;

	/**
	 * Mock checklist service.
	 *
	 * @var OnboardingChecklistService&MockObject
	 */
	private OnboardingChecklistService&MockObject $checklist;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request      = $this->createMock(IRequest::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->scopeGuard   = $this->createMock(GovernanceScopeGuardInterface::class);
		$this->checklist    = $this->createMock(OnboardingChecklistService::class);

	}//end setUp()

	/**
	 * Build the controller for a caller, or for no session when $uid is null.
	 *
	 * @param string|null $uid The caller's UID.
	 *
	 * @return OnboardingController
	 */
	private function controllerFor(?string $uid): OnboardingController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new OnboardingController(
			request: $this->request,
			userSession: $session,
			groupManager: $this->groupManager,
			scopeGuard: $this->scopeGuard,
			checklist: $this->checklist,
		);

	}//end controllerFor()

	/**
	 * Without a session the step is refused with 401 before the record is read.
	 *
	 * @return void
	 */
	public function testUpdateStepRefusesAnUnauthenticatedCaller(): void {
		$this->checklist->expects($this->never())->method('record');

		$response = $this->controllerFor(null)->updateStep('onboarding', 'rec-1', 0);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testUpdateStepRefusesAnUnauthenticatedCaller()

	/**
	 * A refusal from the checklist answers with the refusal's own status and message.
	 *
	 * @return void
	 */
	public function testARefusedStepAnswersWithTheRefusalStatus(): void {
		$this->checklist->expects($this->once())
			->method('record')
			->with('offboarding', 'rec-404')
			->willThrowException(new OnboardingStepRefusedException(message: 'This record does not exist.', status: 404));
		$this->checklist->expects($this->never())->method('completeStep');

		$response = $this->controllerFor('secretary')->updateStep('offboarding', 'rec-404', 2);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'This record does not exist.'], $response->getData());

	}//end testARefusedStepAnswersWithTheRefusalStatus()

	/**
	 * A non-string `status` parameter reaches the checklist as '' and the
	 * scope check runs against the onboarding record's target body.
	 *
	 * @return void
	 */
	public function testANonStringOutcomeIsPassedOnAsEmpty(): void {
		$this->request->method('getParam')->with('status', '')->willReturn(['completed']);
		$this->checklist->method('record')->willReturn(['targetBody' => 'body-7']);
		$this->groupManager->method('isAdmin')->with('secretary')->willReturn(false);
		$this->scopeGuard->expects($this->once())
			->method('isInBodyScope')
			->with('secretary', 'body-7', 'signatory')
			->willReturn(true);
		$this->checklist->expects($this->once())
			->method('completeStep')
			->with('onboarding', 'rec-1', 3, '')
			->willReturn(['id' => 'rec-1', 'steps' => []]);

		$response = $this->controllerFor('secretary')->updateStep('onboarding', 'rec-1', 3);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'rec-1', 'steps' => []], $response->getData());

	}//end testANonStringOutcomeIsPassedOnAsEmpty()
}//end class
