<?php

/**
 * Unit tests for OnboardingController: the per-record access check runs
 * before any group is touched.
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\OnboardingController;
use OCA\Decidiq\Service\InductionPackService;
use OCA\Decidiq\Service\OnboardingProvisioningService;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwayStaffGuard;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OnboardingController.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
#[CoversClass(OnboardingController::class)]
#[UsesClass(PathwayResult::class)]
class OnboardingControllerTest extends TestCase {

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The access guard.
	 *
	 * @var PathwayStaffGuard&MockObject
	 */
	private PathwayStaffGuard&MockObject $guard;

	/**
	 * The provisioning service.
	 *
	 * @var OnboardingProvisioningService&MockObject
	 */
	private OnboardingProvisioningService&MockObject $provisioning;

	/**
	 * The induction pack service.
	 *
	 * @var InductionPackService&MockObject
	 */
	private InductionPackService&MockObject $inductionPack;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session      = $this->createMock(IUserSession::class);
		$this->guard        = $this->createMock(PathwayStaffGuard::class);
		$this->provisioning  = $this->createMock(OnboardingProvisioningService::class);
		$this->inductionPack = $this->createMock(InductionPackService::class);
	}//end setUp()

	/**
	 * A permitted caller gets the service's outcome and status.
	 *
	 * @return void
	 */
	public function testAPermittedCallerAssignsGroups(): void {
		$this->signIn(uid: 'griffier');
		$this->provisioning->method('bodyOf')->with('member-onboarding', 'onb-1')->willReturn('body-1');
		$this->guard->expects($this->once())->method('mayManage')->with('griffier', 'body-1')->willReturn(true);
		$this->provisioning->expects($this->once())->method('provision')->with('onb-1', 'griffier')
			->willReturn(PathwayResult::done(message: 'The groups have been assigned.', details: ['groups' => ['council']]));

		$response = $this->controller()->assignGroups(id: 'onb-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['outcome' => 'done', 'message' => 'The groups have been assigned.', 'groups' => ['council']],
			$response->getData()
		);
	}//end testAPermittedCallerAssignsGroups()

	/**
	 * A caller who may not manage the body is refused and nothing runs.
	 *
	 * @return void
	 */
	public function testACallerOutsideTheSecretariatIsRefused(): void {
		$this->signIn(uid: 'member');
		$this->provisioning->method('bodyOf')->willReturn('body-1');
		$this->guard->method('mayManage')->willReturn(false);
		$this->provisioning->expects($this->never())->method('provision');
		$this->provisioning->expects($this->never())->method('revoke');

		$this->assertSame(403, $this->controller()->assignGroups(id: 'onb-1')->getStatus());
		$this->assertSame(403, $this->controller()->revokeGroups(id: 'off-1')->getStatus());
	}//end testACallerOutsideTheSecretariatIsRefused()

	/**
	 * A permitted caller delivers the pack; a refused one never reaches it.
	 *
	 * @return void
	 */
	public function testTheInductionPackIsDeliveredOnlyForAPermittedCaller(): void {
		$this->signIn(uid: 'griffier');
		$this->provisioning->method('bodyOf')->with('member-onboarding', 'onb-1')->willReturn('body-1');
		$this->guard->method('mayManage')->willReturnOnConsecutiveCalls(true, false);
		$this->inductionPack->expects($this->once())->method('deliver')->with('onb-1', 'griffier')
			->willReturn(PathwayResult::done(message: 'Delivered 2 item(s) to /Welcome.', details: ['folder' => '/Welcome']));

		$first  = $this->controller()->deliverInductionPack(id: 'onb-1');
		$second = $this->controller()->deliverInductionPack(id: 'onb-1');

		$this->assertSame(200, $first->getStatus());
		$this->assertSame('/Welcome', $first->getData()['folder']);
		$this->assertSame(403, $second->getStatus());
	}//end testTheInductionPackIsDeliveredOnlyForAPermittedCaller()

	/**
	 * An unknown record answers 404 without the access check.
	 *
	 * @return void
	 */
	public function testAnUnknownRecordAnswersNotFound(): void {
		$this->signIn(uid: 'griffier');
		$this->provisioning->method('bodyOf')->with('member-offboarding', 'nope')->willReturn(null);
		$this->guard->expects($this->never())->method('mayManage');

		$this->assertSame(404, $this->controller()->revokeGroups(id: 'nope')->getStatus());
	}//end testAnUnknownRecordAnswersNotFound()

	/**
	 * A failed revocation reaches the caller as an error, not as success.
	 *
	 * @return void
	 */
	public function testAFailedRevocationIsReportedAsAnError(): void {
		$this->signIn(uid: 'griffier');
		$this->provisioning->method('bodyOf')->willReturn('body-1');
		$this->guard->method('mayManage')->willReturn(true);
		$this->provisioning->method('revoke')->with('off-1', 'griffier')
			->willReturn(PathwayResult::failed(message: 'The member is still in: council. The step stays open.'));

		$response = $this->controller()->revokeGroups(id: 'off-1');

		$this->assertSame(502, $response->getStatus());
		$this->assertSame('failed', $response->getData()['outcome']);
	}//end testAFailedRevocationIsReportedAsAnError()

	/**
	 * Without a session nothing is looked up.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->provisioning->expects($this->never())->method('bodyOf');

		$this->assertSame(401, $this->controller()->assignGroups(id: 'onb-1')->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * Sign a user in.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * The controller under test.
	 *
	 * @return OnboardingController
	 */
	private function controller(): OnboardingController {
		return new OnboardingController(
			request: $this->createMock(IRequest::class),
			userSession: $this->session,
			guard: $this->guard,
			provisioning: $this->provisioning,
			inductionPack: $this->inductionPack,
		);
	}//end controller()

}//end class
