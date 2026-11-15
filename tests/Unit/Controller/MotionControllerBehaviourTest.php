<?php

/**
 * Behaviour tests for MotionController — role guards, validation and error mapping.
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

use OCA\Decidiq\Controller\MotionController;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\MotionStages;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Authenticated-path tests for MotionController.
 *
 * Covers the per-meeting and global-fallback role guards, request validation,
 * and the mapping of service exceptions onto HTTP status codes.
 *
 * @covers \OCA\Decidiq\Controller\MotionController
 */
class MotionControllerBehaviourTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock MotionService.
	 *
	 * @var MotionService&MockObject
	 */
	private MotionService&MockObject $motionService;

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
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Mock MotionStages.
	 *
	 * @var MotionStages&MockObject
	 */
	private MotionStages&MockObject $motionStages;

	/**
	 * The controller under test.
	 *
	 * @var MotionController
	 */
	private MotionController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->motionService = $this->createMock(MotionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->motionStages = $this->createMock(MotionStages::class);

		$this->controller = new MotionController(
			request: $this->request,
			motionService: $this->motionService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			appConfig: $this->appConfig,
			participantResolver: $this->participantResolver,
			motionStages: $this->motionStages,
		);

	}//end setUp()

	/**
	 * Sign a user into the mocked session.
	 *
	 * @param string $uid         The user id.
	 * @param string $displayName The display name.
	 *
	 * @return void
	 */
	private function signIn(string $uid='chair1', string $displayName='A. de Vries'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * Make the per-meeting role check pass for motion 'm-1' in meeting 'meet-1'.
	 *
	 * @return void
	 */
	private function grantMeetingRole(): void {
		$this->motionService->method('resolveMeetingId')->with(motionId: 'm-1')->willReturn('meet-1');
		$this->participantResolver->method('hasRole')
			->with(meetingId: 'meet-1', nextcloudUid: 'chair1', roles: ['chair', 'secretary'])
			->willReturn(true);

	}//end grantMeetingRole()

	/**
	 * Make the global fallback (no meeting) grant chair authority via admin.
	 *
	 * @return void
	 */
	private function grantGlobalAdmin(): void {
		$this->appConfig->method('getValueString')->with('decidiq', 'chair_group', '')->willReturn('');
		$this->groupManager->method('isAdmin')->with('chair1')->willReturn(true);

	}//end grantGlobalAdmin()

	/**
	 * Stub the request parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParams')->willReturn($params);

	}//end withParams()

	/**
	 * A chair of the motion's meeting transitions it; the trimmed outcome is passed through.
	 *
	 * @return void
	 */
	public function testTransitionByMeetingChairPassesTrimmedOutcome(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['newState' => 'decided', 'outcome' => '  adopted ']);
		$this->motionService->expects($this->once())
			->method('transitionLifecycle')
			->with(objectId: 'm-1', objectType: 'motion', newState: 'decided', actorId: 'chair1', outcome: 'adopted');

		$response = $this->controller->transition('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true, 'newState' => 'decided', 'outcome' => 'adopted'], $response->getData());

	}//end testTransitionByMeetingChairPassesTrimmedOutcome()

	/**
	 * A blank outcome is mapped to null, not passed as an empty string.
	 *
	 * @return void
	 */
	public function testTransitionBlankOutcomeBecomesNull(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['newState' => 'deliberating', 'outcome' => '   ']);
		$this->motionService->expects($this->once())
			->method('transitionLifecycle')
			->with(objectId: 'm-1', objectType: 'motion', newState: 'deliberating', actorId: 'chair1', outcome: null);

		$response = $this->controller->transition('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($response->getData()['outcome']);

	}//end testTransitionBlankOutcomeBecomesNull()

	/**
	 * A user without the meeting role gets the scoped 403 and no transition happens.
	 *
	 * @return void
	 */
	public function testTransitionWithoutMeetingRoleIs403(): void {
		$this->signIn();
		$this->motionService->method('resolveMeetingId')->willReturn('meet-1');
		$this->participantResolver->method('hasRole')->willReturn(false);
		$this->withParams(['newState' => 'voting']);
		$this->motionStages->expects($this->never())->method('mayWithdraw');
		$this->motionService->expects($this->never())->method('transitionLifecycle');

		$response = $this->controller->transition('m-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Chair or secretary role required for this meeting'], $response->getData());

	}//end testTransitionWithoutMeetingRoleIs403()

	/**
	 * The submitter may withdraw her own motion despite lacking the chair role.
	 *
	 * @return void
	 */
	public function testTransitionOwnWithdrawalBypassesGuard(): void {
		$this->signIn('member1');
		$this->motionService->method('resolveMeetingId')->willReturn('meet-1');
		$this->participantResolver->method('hasRole')->willReturn(false);
		$this->withParams(['newState' => 'withdrawn']);
		$this->motionStages->expects($this->once())
			->method('mayWithdraw')
			->with(motionId: 'm-1', uid: 'member1')
			->willReturn(true);
		$this->motionService->expects($this->once())
			->method('transitionLifecycle')
			->with(objectId: 'm-1', objectType: 'motion', newState: 'withdrawn', actorId: 'member1', outcome: null);

		$response = $this->controller->transition('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('withdrawn', $response->getData()['newState']);

	}//end testTransitionOwnWithdrawalBypassesGuard()

	/**
	 * A service InvalidArgumentException maps onto 400.
	 *
	 * @return void
	 */
	public function testTransitionInvalidArgumentIs400(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['newState' => 'bogus']);
		$this->motionService->method('transitionLifecycle')
			->willThrowException(new \InvalidArgumentException('Invalid state bogus'));

		$response = $this->controller->transition('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Invalid state bogus'], $response->getData());

	}//end testTransitionInvalidArgumentIs400()

	/**
	 * A service RuntimeException maps onto 404.
	 *
	 * @return void
	 */
	public function testTransitionRuntimeIs404(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['newState' => 'voting']);
		$this->motionService->method('transitionLifecycle')
			->willThrowException(new \RuntimeException('Motion not found'));

		$response = $this->controller->transition('m-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Motion not found'], $response->getData());

	}//end testTransitionRuntimeIs404()

	/**
	 * transitions() is 401 when anonymous.
	 *
	 * @return void
	 */
	public function testTransitionsAnonymousIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->motionStages->expects($this->never())->method('forCaller');

		$response = $this->controller->transitions('m-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Unauthorized'], $response->getData());

	}//end testTransitionsAnonymousIs401()

	/**
	 * transitions() is 404 when the caller cannot read the motion.
	 *
	 * @return void
	 */
	public function testTransitionsUnreadableIs404(): void {
		$this->signIn('member1');
		$this->motionStages->expects($this->once())
			->method('forCaller')
			->with(motionId: 'm-1', uid: 'member1')
			->willReturn(null);

		$response = $this->controller->transitions('m-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Motion not found.'], $response->getData());

	}//end testTransitionsUnreadableIs404()

	/**
	 * transitions() returns the stage answer verbatim.
	 *
	 * @return void
	 */
	public function testTransitionsReturnsAnswer(): void {
		$this->signIn('member1');
		$answer = ['lifecycle' => 'proposed', 'outcome' => null, 'actions' => ['withdrawn']];
		$this->motionStages->method('forCaller')->with(motionId: 'm-1', uid: 'member1')->willReturn($answer);

		$response = $this->controller->transitions('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($answer, $response->getData());

	}//end testTransitionsReturnsAnswer()

	/**
	 * coSignRequest() rejects an empty participant list with 400.
	 *
	 * @return void
	 */
	public function testCoSignRequestWithoutParticipantsIs400(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams([]);
		$this->motionService->expects($this->never())->method('requestCoSignature');

		$response = $this->controller->coSignRequest('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'participantIds is required'], $response->getData());

	}//end testCoSignRequestWithoutParticipantsIs400()

	/**
	 * coSignRequest() forwards the participant ids and answers success.
	 *
	 * @return void
	 */
	public function testCoSignRequestSucceeds(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['participantIds' => ['u1', 'u2']]);
		$this->motionService->expects($this->once())
			->method('requestCoSignature')
			->with(motionId: 'm-1', participantIds: ['u1', 'u2']);

		$response = $this->controller->coSignRequest('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true], $response->getData());

	}//end testCoSignRequestSucceeds()

	/**
	 * coSignRequest() maps a RuntimeException onto 404.
	 *
	 * @return void
	 */
	public function testCoSignRequestRuntimeIs404(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['participantIds' => ['u1']]);
		$this->motionService->method('requestCoSignature')
			->willThrowException(new \RuntimeException('Motion gone'));

		$response = $this->controller->coSignRequest('m-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Motion gone'], $response->getData());

	}//end testCoSignRequestRuntimeIs404()

	/**
	 * coSignConfirm() rejects a user with a blank display name.
	 *
	 * @return void
	 */
	public function testCoSignConfirmBlankDisplayNameIs400(): void {
		$this->signIn('u1', '  ');
		$this->motionService->expects($this->never())->method('isPendingCoSigner');
		$this->motionService->expects($this->never())->method('addCoSigner');

		$response = $this->controller->coSignConfirm('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'displayName is required'], $response->getData());

	}//end testCoSignConfirmBlankDisplayNameIs400()

	/**
	 * coSignConfirm() refuses a user who was not invited to co-sign.
	 *
	 * @return void
	 */
	public function testCoSignConfirmNotInvitedIs403(): void {
		$this->signIn('u1', 'B. Jansen');
		$this->motionService->expects($this->once())
			->method('isPendingCoSigner')
			->with(motionId: 'm-1', nextcloudUid: 'u1')
			->willReturn(false);
		$this->motionService->expects($this->never())->method('addCoSigner');

		$response = $this->controller->coSignConfirm('m-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(
			['message' => 'U bent niet uitgenodigd om deze motie mede te ondertekenen'],
			$response->getData()
		);

	}//end testCoSignConfirmNotInvitedIs403()

	/**
	 * coSignConfirm() adds the session display name as co-signer.
	 *
	 * @return void
	 */
	public function testCoSignConfirmAddsSessionDisplayName(): void {
		$this->signIn('u1', 'B. Jansen');
		$this->motionService->method('isPendingCoSigner')->willReturn(true);
		$this->motionService->expects($this->once())
			->method('addCoSigner')
			->with(motionId: 'm-1', coSignerName: 'B. Jansen');

		$response = $this->controller->coSignConfirm('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true], $response->getData());

	}//end testCoSignConfirmAddsSessionDisplayName()

	/**
	 * coSignConfirm() maps a RuntimeException onto 404.
	 *
	 * @return void
	 */
	public function testCoSignConfirmRuntimeIs404(): void {
		$this->signIn('u1', 'B. Jansen');
		$this->motionService->method('isPendingCoSigner')->willReturn(true);
		$this->motionService->method('addCoSigner')->willThrowException(new \RuntimeException('No such motion'));

		$response = $this->controller->coSignConfirm('m-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'No such motion'], $response->getData());

	}//end testCoSignConfirmRuntimeIs404()

	/**
	 * budgetImpact() casts and forwards the budget fields.
	 *
	 * @return void
	 */
	public function testBudgetImpactSavesFields(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams(['budgetLine' => '6.1 Onderwijs', 'amountDelta' => '-1250.5', 'rationale' => 'Bezuiniging']);
		$this->motionService->expects($this->once())
			->method('saveBudgetImpact')
			->with(motionId: 'm-1', budgetLine: '6.1 Onderwijs', amountDelta: -1250.5, rationale: 'Bezuiniging');

		$response = $this->controller->budgetImpact('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true], $response->getData());

	}//end testBudgetImpactSavesFields()

	/**
	 * budgetImpact() maps a RuntimeException onto 404.
	 *
	 * @return void
	 */
	public function testBudgetImpactRuntimeIs404(): void {
		$this->signIn();
		$this->grantMeetingRole();
		$this->withParams([]);
		$this->motionService->expects($this->once())
			->method('saveBudgetImpact')
			->with(motionId: 'm-1', budgetLine: '', amountDelta: 0.0, rationale: '')
			->willThrowException(new \RuntimeException('Motion missing'));

		$response = $this->controller->budgetImpact('m-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Motion missing'], $response->getData());

	}//end testBudgetImpactRuntimeIs404()

	/**
	 * amendmentTransition() denies a non-admin when no chair group is configured.
	 *
	 * @return void
	 */
	public function testAmendmentTransitionGlobalFallbackDeniesNonAdmin(): void {
		$this->signIn();
		$this->appConfig->method('getValueString')->willReturn('');
		$this->groupManager->expects($this->once())->method('isAdmin')->with('chair1')->willReturn(false);
		$this->motionService->expects($this->never())->method('resolveMeetingId');
		$this->motionService->expects($this->never())->method('transitionLifecycle');

		$response = $this->controller->amendmentTransition('a-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Chair or secretary role required'], $response->getData());

	}//end testAmendmentTransitionGlobalFallbackDeniesNonAdmin()

	/**
	 * amendmentTransition() accepts a member of the configured chair group.
	 *
	 * @return void
	 */
	public function testAmendmentTransitionByChairGroupMember(): void {
		$this->signIn();
		$this->appConfig->method('getValueString')->with('decidiq', 'chair_group', '')->willReturn('griffie');
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->groupManager->expects($this->once())->method('isInGroup')->with('chair1', 'griffie')->willReturn(true);
		$this->withParams(['newState' => 'decided', 'outcome' => 'rejected']);
		$this->motionService->expects($this->once())
			->method('transitionLifecycle')
			->with(objectId: 'a-1', objectType: 'amendment', newState: 'decided', actorId: 'chair1', outcome: 'rejected');

		$response = $this->controller->amendmentTransition('a-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true, 'newState' => 'decided', 'outcome' => 'rejected'], $response->getData());

	}//end testAmendmentTransitionByChairGroupMember()

	/**
	 * amendmentTransition() denies a user outside the configured chair group.
	 *
	 * @return void
	 */
	public function testAmendmentTransitionOutsideChairGroupIs403(): void {
		$this->signIn();
		$this->appConfig->method('getValueString')->willReturn('griffie');
		$this->groupManager->method('isInGroup')->with('chair1', 'griffie')->willReturn(false);
		$this->motionService->expects($this->never())->method('transitionLifecycle');

		$response = $this->controller->amendmentTransition('a-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Chair or secretary role required'], $response->getData());

	}//end testAmendmentTransitionOutsideChairGroupIs403()

	/**
	 * amendmentTransition() maps InvalidArgumentException onto 400.
	 *
	 * @return void
	 */
	public function testAmendmentTransitionInvalidArgumentIs400(): void {
		$this->signIn();
		$this->grantGlobalAdmin();
		$this->withParams(['newState' => 'decided']);
		$this->motionService->method('transitionLifecycle')
			->willThrowException(new \InvalidArgumentException('outcome required'));

		$response = $this->controller->amendmentTransition('a-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'outcome required'], $response->getData());

	}//end testAmendmentTransitionInvalidArgumentIs400()

	/**
	 * amendmentTransition() maps RuntimeException onto 404.
	 *
	 * @return void
	 */
	public function testAmendmentTransitionRuntimeIs404(): void {
		$this->signIn();
		$this->grantGlobalAdmin();
		$this->withParams(['newState' => 'voting']);
		$this->motionService->method('transitionLifecycle')
			->willThrowException(new \RuntimeException('Amendment not found'));

		$response = $this->controller->amendmentTransition('a-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Amendment not found'], $response->getData());

	}//end testAmendmentTransitionRuntimeIs404()

	/**
	 * forward() requires target body and justification.
	 *
	 * @return void
	 */
	public function testForwardMissingFieldsIs400(): void {
		$this->signIn();
		$this->grantGlobalAdmin();
		$this->withParams(['targetBodyId' => 'body-2']);
		$this->motionService->expects($this->never())->method('forwardMotion');

		$response = $this->controller->forward('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['message' => 'targetBodyId, justification, and authentication required'],
			$response->getData()
		);

	}//end testForwardMissingFieldsIs400()

	/**
	 * forward() returns the forwarded motion from the service.
	 *
	 * @return void
	 */
	public function testForwardReturnsForwardedMotion(): void {
		$this->signIn();
		$this->grantGlobalAdmin();
		$this->withParams(['targetBodyId' => 'body-2', 'justification' => 'Bevoegdheid']);
		$forwarded = ['id' => 'm-2', 'governanceBody' => 'body-2'];
		$this->motionService->expects($this->once())
			->method('forwardMotion')
			->with(motionId: 'm-1', targetBodyId: 'body-2', actorId: 'chair1', justification: 'Bevoegdheid')
			->willReturn($forwarded);

		$response = $this->controller->forward('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($forwarded, $response->getData());

	}//end testForwardReturnsForwardedMotion()

	/**
	 * forward() maps a RuntimeException onto 400.
	 *
	 * @return void
	 */
	public function testForwardRuntimeIs400(): void {
		$this->signIn();
		$this->grantGlobalAdmin();
		$this->withParams(['targetBodyId' => 'body-2', 'justification' => 'Bevoegdheid']);
		$this->motionService->method('forwardMotion')
			->willThrowException(new \RuntimeException('Target body not found'));

		$response = $this->controller->forward('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Target body not found'], $response->getData());

	}//end testForwardRuntimeIs400()

	/**
	 * amendmentOrder() refuses a secretary: only the chair role suffices.
	 *
	 * @return void
	 */
	public function testAmendmentOrderRequiresChairRole(): void {
		$this->signIn('secretary1');
		$this->motionService->method('resolveMeetingId')->with(motionId: 'm-1')->willReturn('meet-1');
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with(meetingId: 'meet-1', nextcloudUid: 'secretary1', roles: ['chair'])
			->willReturn(false);
		$this->motionService->expects($this->never())->method('setAmendmentVotingOrder');

		$response = $this->controller->amendmentOrder('m-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Chair role required for this meeting'], $response->getData());

	}//end testAmendmentOrderRequiresChairRole()

	/**
	 * Grant the per-meeting chair role for amendmentOrder().
	 *
	 * @return void
	 */
	private function grantChairRole(): void {
		$this->motionService->method('resolveMeetingId')->willReturn('meet-1');
		$this->participantResolver->method('hasRole')
			->with(meetingId: 'meet-1', nextcloudUid: 'chair1', roles: ['chair'])
			->willReturn(true);

	}//end grantChairRole()

	/**
	 * amendmentOrder() rejects a non-array order.
	 *
	 * @return void
	 */
	public function testAmendmentOrderNonArrayIs400(): void {
		$this->signIn();
		$this->grantChairRole();
		$this->withParams(['orderedAmendmentIds' => 'a-1,a-2']);
		$this->motionService->expects($this->never())->method('setAmendmentVotingOrder');

		$response = $this->controller->amendmentOrder('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'orderedAmendmentIds (non-empty array) is required'], $response->getData());

	}//end testAmendmentOrderNonArrayIs400()

	/**
	 * amendmentOrder() rejects an empty order.
	 *
	 * @return void
	 */
	public function testAmendmentOrderEmptyArrayIs400(): void {
		$this->signIn();
		$this->grantChairRole();
		$this->withParams(['orderedAmendmentIds' => []]);
		$this->motionService->expects($this->never())->method('setAmendmentVotingOrder');

		$response = $this->controller->amendmentOrder('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAmendmentOrderEmptyArrayIs400()

	/**
	 * amendmentOrder() normalises ids to a string list and returns the updated amendments.
	 *
	 * @return void
	 */
	public function testAmendmentOrderSucceeds(): void {
		$this->signIn();
		$this->grantChairRole();
		$this->withParams(['orderedAmendmentIds' => ['x' => 'a-2', 'y' => 17]]);
		$updated = [['id' => 'a-2', 'votingOrder' => 1], ['id' => '17', 'votingOrder' => 2]];
		$this->motionService->expects($this->once())
			->method('setAmendmentVotingOrder')
			->with(motionId: 'm-1', orderedAmendmentIds: ['a-2', '17'], actorId: 'chair1')
			->willReturn($updated);

		$response = $this->controller->amendmentOrder('m-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true, 'amendments' => $updated], $response->getData());

	}//end testAmendmentOrderSucceeds()

	/**
	 * amendmentOrder() maps InvalidArgumentException onto 400.
	 *
	 * @return void
	 */
	public function testAmendmentOrderInvalidArgumentIs400(): void {
		$this->signIn();
		$this->grantChairRole();
		$this->withParams(['orderedAmendmentIds' => ['a-9']]);
		$this->motionService->method('setAmendmentVotingOrder')
			->willThrowException(new \InvalidArgumentException('Unknown amendment a-9'));

		$response = $this->controller->amendmentOrder('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Unknown amendment a-9'], $response->getData());

	}//end testAmendmentOrderInvalidArgumentIs400()

	/**
	 * amendmentOrder() maps RuntimeException onto 404.
	 *
	 * @return void
	 */
	public function testAmendmentOrderRuntimeIs404(): void {
		$this->signIn();
		$this->grantChairRole();
		$this->withParams(['orderedAmendmentIds' => ['a-1']]);
		$this->motionService->method('setAmendmentVotingOrder')
			->willThrowException(new \RuntimeException('Motion not found'));

		$response = $this->controller->amendmentOrder('m-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Motion not found'], $response->getData());

	}//end testAmendmentOrderRuntimeIs404()

}//end class
