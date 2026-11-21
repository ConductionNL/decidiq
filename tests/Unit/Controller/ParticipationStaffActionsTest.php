<?php

/**
 * Unit tests for ParticipationController staff actions and anonymous intake.
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

use OCA\Decidiq\Controller\ParticipationController;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\Decidiq\Service\ParticipationResponder;
use OCA\Decidiq\Service\ParticipationStaffGuard;
use OCA\Decidiq\Service\ReactionIntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Staff-guarded consultation / reaction endpoints over the REAL responder.
 *
 * @covers \OCA\Decidiq\Controller\ParticipationController
 * @uses   \OCA\Decidiq\Service\ParticipationResponder
 */
class ParticipationStaffActionsTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ParticipationLifecycleService.
	 *
	 * @var ParticipationLifecycleService&MockObject
	 */
	private ParticipationLifecycleService&MockObject $lifecycleService;

	/**
	 * Mock ReactionIntakeService.
	 *
	 * @var ReactionIntakeService&MockObject
	 */
	private ReactionIntakeService&MockObject $intakeService;

	/**
	 * Mock ParticipationPublicationService.
	 *
	 * @var ParticipationPublicationService&MockObject
	 */
	private ParticipationPublicationService&MockObject $publicationService;

	/**
	 * Mock ParticipationStaffGuard.
	 *
	 * @var ParticipationStaffGuard&MockObject
	 */
	private ParticipationStaffGuard&MockObject $staffGuard;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->lifecycleService = $this->createMock(ParticipationLifecycleService::class);
		$this->intakeService = $this->createMock(ReactionIntakeService::class);
		$this->publicationService = $this->createMock(ParticipationPublicationService::class);
		$this->staffGuard = $this->createMock(ParticipationStaffGuard::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ParticipationController
	 */
	private function controller(): ParticipationController {
		return new ParticipationController(
			$this->request,
			$this->lifecycleService,
			$this->intakeService,
			$this->publicationService,
			new ParticipationResponder($this->staffGuard),
		);

	}//end controller()

	/**
	 * Sign in as a staff member.
	 *
	 * @return void
	 */
	private function asStaff(): void {
		$this->staffGuard->method('currentUid')->willReturn('clerk');
		$this->staffGuard->method('isStaff')->willReturn(true);

	}//end asStaff()

	/**
	 * Staff transition is enveloped under "consultation".
	 *
	 * @return void
	 */
	public function testTransitionConsultationAsStaff(): void {
		$this->asStaff();
		$this->lifecycleService->expects($this->once())
			->method('transitionConsultation')
			->with('c-1', 'open')
			->willReturn(['id' => 'c-1', 'status' => 'open']);

		$response = $this->controller()->transitionConsultation('c-1', 'open');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['consultation' => ['id' => 'c-1', 'status' => 'open']], $response->getData());

	}//end testTransitionConsultationAsStaff()

	/**
	 * A non-staff user is refused and the service is never called.
	 *
	 * @return void
	 */
	public function testTransitionConsultationRefusedForNonStaff(): void {
		$this->staffGuard->method('currentUid')->willReturn('citizen');
		$this->staffGuard->method('isStaff')->willReturn(false);
		$this->lifecycleService->expects($this->never())->method('transitionConsultation');

		$response = $this->controller()->transitionConsultation('c-1', 'open');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Governance-body authority required'], $response->getData());

	}//end testTransitionConsultationRefusedForNonStaff()

	/**
	 * An invalid transition is a 400.
	 *
	 * @return void
	 */
	public function testTransitionConsultationInvalidIsBadRequest(): void {
		$this->asStaff();
		$this->lifecycleService->method('transitionConsultation')
			->willThrowException(new \InvalidArgumentException('Invalid transition draft -> closed'));

		$response = $this->controller()->transitionConsultation('c-1', 'closed');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Invalid transition draft -> closed'], $response->getData());

	}//end testTransitionConsultationInvalidIsBadRequest()

	/**
	 * An empty approval reason is passed as null.
	 *
	 * @return void
	 */
	public function testApproveReactionWithoutReasonPassesNull(): void {
		$this->asStaff();
		$this->intakeService->expects($this->once())
			->method('approveReaction')
			->with('r-1', null)
			->willReturn(['id' => 'r-1', 'status' => 'approved']);

		$response = $this->controller()->approveReaction('r-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['reaction' => ['id' => 'r-1', 'status' => 'approved']], $response->getData());

	}//end testApproveReactionWithoutReasonPassesNull()

	/**
	 * A non-empty approval reason is passed through.
	 *
	 * @return void
	 */
	public function testApproveReactionWithReasonPassesIt(): void {
		$this->asStaff();
		$this->intakeService->expects($this->once())
			->method('approveReaction')
			->with('r-1', 'Constructief')
			->willReturn(['id' => 'r-1']);

		self::assertSame(Http::STATUS_OK, $this->controller()->approveReaction('r-1', 'Constructief')->getStatus());

	}//end testApproveReactionWithReasonPassesIt()

	/**
	 * Approving without a session is a 401.
	 *
	 * @return void
	 */
	public function testApproveReactionWithoutSessionIsUnauthorized(): void {
		$this->staffGuard->method('currentUid')->willReturn(null);
		$this->intakeService->expects($this->never())->method('approveReaction');

		$response = $this->controller()->approveReaction('r-1', 'x');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Unauthorized'], $response->getData());

	}//end testApproveReactionWithoutSessionIsUnauthorized()

	/**
	 * Rejecting passes the reason; a non-pending reaction maps to 409.
	 *
	 * @return void
	 */
	public function testRejectReactionPassesReason(): void {
		$this->asStaff();
		$this->intakeService->expects($this->once())
			->method('rejectReaction')
			->with('r-2', 'Off-topic')
			->willReturn(['id' => 'r-2', 'status' => 'rejected']);

		$response = $this->controller()->rejectReaction('r-2', 'Off-topic');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['reaction' => ['id' => 'r-2', 'status' => 'rejected']], $response->getData());

	}//end testRejectReactionPassesReason()

	/**
	 * A generic runtime failure while rejecting is a 409.
	 *
	 * @return void
	 */
	public function testRejectReactionConflict(): void {
		$this->asStaff();
		$this->intakeService->method('rejectReaction')
			->willThrowException(new \RuntimeException('Reaction is not pending'));

		$response = $this->controller()->rejectReaction('r-2', 'Off-topic');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Reaction is not pending'], $response->getData());

	}//end testRejectReactionConflict()

	/**
	 * Publishing consultation results returns the raw payload.
	 *
	 * @return void
	 */
	public function testPublishConsultationResultsReturnsRawPayload(): void {
		$this->asStaff();
		$this->publicationService->expects($this->once())
			->method('publishConsultationResults')
			->with('c-1', 'Dank voor uw reacties')
			->willReturn(['published' => true, 'reactionCount' => 4]);

		$response = $this->controller()->publishConsultationResults('c-1', 'Dank voor uw reacties');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['published' => true, 'reactionCount' => 4], $response->getData());

	}//end testPublishConsultationResultsReturnsRawPayload()

	/**
	 * A closed window while publishing results is a 400.
	 *
	 * @return void
	 */
	public function testPublishConsultationResultsWindowClosedIsBadRequest(): void {
		$this->asStaff();
		$this->publicationService->method('publishConsultationResults')
			->willThrowException(new ParticipationWindowClosedException('Consultation still open'));

		$response = $this->controller()->publishConsultationResults('c-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Consultation still open'], $response->getData());

	}//end testPublishConsultationResultsWindowClosedIsBadRequest()

	/**
	 * Publishing a single reaction is enveloped under "reaction".
	 *
	 * @return void
	 */
	public function testPublishReactionEnveloped(): void {
		$this->asStaff();
		$this->publicationService->expects($this->once())
			->method('publishReaction')
			->with('r-3')
			->willReturn(['id' => 'r-3', 'published' => true]);

		$response = $this->controller()->publishReaction('r-3');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['reaction' => ['id' => 'r-3', 'published' => true]], $response->getData());

	}//end testPublishReactionEnveloped()

	/**
	 * A validation failure while publishing a reaction is a 422.
	 *
	 * @return void
	 */
	public function testPublishReactionValidationIsUnprocessable(): void {
		$this->asStaff();
		$this->publicationService->method('publishReaction')
			->willThrowException(new ParticipationValidationException('Reaction is not approved'));

		$response = $this->controller()->publishReaction('r-3');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'Reaction is not approved'], $response->getData());

	}//end testPublishReactionValidationIsUnprocessable()

	/**
	 * Publishing a reaction is refused for a non-staff user.
	 *
	 * @return void
	 */
	public function testPublishReactionRefusedForNonStaff(): void {
		$this->staffGuard->method('currentUid')->willReturn('citizen');
		$this->staffGuard->method('isStaff')->willReturn(false);
		$this->publicationService->expects($this->never())->method('publishReaction');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->publishReaction('r-3')->getStatus());

	}//end testPublishReactionRefusedForNonStaff()
}//end class
