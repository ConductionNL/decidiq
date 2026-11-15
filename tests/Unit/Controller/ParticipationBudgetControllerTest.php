<?php

/**
 * Unit tests for ParticipationBudgetController.
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

use OCA\Decidiq\Controller\ParticipationBudgetController;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Service\BudgetVotingService;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\Decidiq\Service\ParticipationResponder;
use OCA\Decidiq\Service\ParticipationStaffGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Participatory-budget endpoints over the REAL responder.
 *
 * @covers \OCA\Decidiq\Controller\ParticipationBudgetController
 * @uses   \OCA\Decidiq\Service\ParticipationResponder
 */
class ParticipationBudgetControllerTest extends TestCase {

	/**
	 * Mock ParticipationLifecycleService.
	 *
	 * @var ParticipationLifecycleService&MockObject
	 */
	private ParticipationLifecycleService&MockObject $lifecycleService;

	/**
	 * Mock BudgetVotingService.
	 *
	 * @var BudgetVotingService&MockObject
	 */
	private BudgetVotingService&MockObject $budgetService;

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

		$this->lifecycleService = $this->createMock(ParticipationLifecycleService::class);
		$this->budgetService = $this->createMock(BudgetVotingService::class);
		$this->publicationService = $this->createMock(ParticipationPublicationService::class);
		$this->staffGuard = $this->createMock(ParticipationStaffGuard::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ParticipationBudgetController
	 */
	private function controller(): ParticipationBudgetController {
		return new ParticipationBudgetController(
			$this->createMock(IRequest::class),
			$this->lifecycleService,
			$this->budgetService,
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
	 * Sign in as a non-staff citizen.
	 *
	 * @return void
	 */
	private function asCitizen(): void {
		$this->staffGuard->method('currentUid')->willReturn('citizen');
		$this->staffGuard->method('isStaff')->willReturn(false);

	}//end asCitizen()

	/**
	 * Staff transition is enveloped under "budgetRound".
	 *
	 * @return void
	 */
	public function testTransitionBudgetRoundAsStaff(): void {
		$this->asStaff();
		$this->lifecycleService->expects($this->once())
			->method('transitionBudgetRound')
			->with('b-1', 'voting')
			->willReturn(['id' => 'b-1', 'status' => 'voting']);

		$response = $this->controller()->transitionBudgetRound('b-1', 'voting');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['budgetRound' => ['id' => 'b-1', 'status' => 'voting']], $response->getData());

	}//end testTransitionBudgetRoundAsStaff()

	/**
	 * A non-staff user cannot transition a round.
	 *
	 * @return void
	 */
	public function testTransitionBudgetRoundRefusedForNonStaff(): void {
		$this->asCitizen();
		$this->lifecycleService->expects($this->never())->method('transitionBudgetRound');

		$response = $this->controller()->transitionBudgetRound('b-1', 'voting');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Governance-body authority required'], $response->getData());

	}//end testTransitionBudgetRoundRefusedForNonStaff()

	/**
	 * An invalid transition is a 400.
	 *
	 * @return void
	 */
	public function testTransitionBudgetRoundInvalidIsBadRequest(): void {
		$this->asStaff();
		$this->lifecycleService->method('transitionBudgetRound')
			->willThrowException(new \InvalidArgumentException('Invalid transition'));

		$response = $this->controller()->transitionBudgetRound('b-1', 'closed');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Invalid transition'], $response->getData());

	}//end testTransitionBudgetRoundInvalidIsBadRequest()

	/**
	 * A proposal outside the submission window is a 400.
	 *
	 * @return void
	 */
	public function testSubmitProposalOutsideWindowIsBadRequest(): void {
		$this->asCitizen();
		$this->budgetService->expects($this->once())
			->method('submitProposal')
			->with('b-1', 'Speeltuin', 'Nieuwe speeltuin', 2500.0, 'citizen', 'groen')
			->willThrowException(new ParticipationWindowClosedException('Round does not accept proposals'));

		$response = $this->controller()->submitProposal('b-1', 'Speeltuin', 'Nieuwe speeltuin', 2500.0, 'groen');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Round does not accept proposals'], $response->getData());

	}//end testSubmitProposalOutsideWindowIsBadRequest()

	/**
	 * Absent approve keeps the historical approve-by-default contract.
	 *
	 * @return void
	 */
	public function testValidateProposalDefaultsToApprove(): void {
		$this->asStaff();
		$this->budgetService->expects($this->once())
			->method('validateProposal')
			->with('p-1', true)
			->willReturn(['id' => 'p-1', 'status' => 'validated']);

		$response = $this->controller()->validateProposal('p-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['proposal' => ['id' => 'p-1', 'status' => 'validated']], $response->getData());

	}//end testValidateProposalDefaultsToApprove()

	/**
	 * Explicit true approves.
	 *
	 * @return void
	 */
	public function testValidateProposalExplicitTrueApproves(): void {
		$this->asStaff();
		$this->budgetService->expects($this->once())
			->method('validateProposal')
			->with('p-1', true)
			->willReturn(['id' => 'p-1']);

		self::assertSame(Http::STATUS_OK, $this->controller()->validateProposal('p-1', true)->getStatus());

	}//end testValidateProposalExplicitTrueApproves()

	/**
	 * Explicit false rejects.
	 *
	 * @return void
	 */
	public function testValidateProposalFalseRejects(): void {
		$this->asStaff();
		$this->budgetService->expects($this->once())
			->method('validateProposal')
			->with('p-1', false)
			->willReturn(['id' => 'p-1', 'status' => 'rejected']);

		$response = $this->controller()->validateProposal('p-1', false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['proposal' => ['id' => 'p-1', 'status' => 'rejected']], $response->getData());

	}//end testValidateProposalFalseRejects()

	/**
	 * Validating is refused for a non-staff user.
	 *
	 * @return void
	 */
	public function testValidateProposalRefusedForNonStaff(): void {
		$this->asCitizen();
		$this->budgetService->expects($this->never())->method('validateProposal');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->validateProposal('p-1', false)->getStatus());

	}//end testValidateProposalRefusedForNonStaff()

	/**
	 * A duplicate advisory vote is a 409.
	 *
	 * @return void
	 */
	public function testCastAdvisoryVoteDuplicateIsConflict(): void {
		$this->asCitizen();
		$this->budgetService->expects($this->once())
			->method('castAdvisoryVote')
			->with('p-1', 'citizen', 'voor')
			->willThrowException(new \RuntimeException('Already voted'));

		$response = $this->controller()->castAdvisoryVote('p-1', 'voor');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Already voted'], $response->getData());

	}//end testCastAdvisoryVoteDuplicateIsConflict()

	/**
	 * An invalid vote value is a 422.
	 *
	 * @return void
	 */
	public function testCastAdvisoryVoteInvalidValueIsUnprocessable(): void {
		$this->asCitizen();
		$this->budgetService->method('castAdvisoryVote')
			->willThrowException(new ParticipationValidationException('value must be voor or tegen'));

		$response = $this->controller()->castAdvisoryVote('p-1', 'misschien');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'value must be voor or tegen'], $response->getData());

	}//end testCastAdvisoryVoteInvalidValueIsUnprocessable()

	/**
	 * Publishing budget results returns the raw payload.
	 *
	 * @return void
	 */
	public function testPublishBudgetResultsReturnsRawPayload(): void {
		$this->asStaff();
		$this->publicationService->expects($this->once())
			->method('publishBudgetResults')
			->with('b-1')
			->willReturn(['published' => true, 'allocated' => 10000]);

		$response = $this->controller()->publishBudgetResults('b-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['published' => true, 'allocated' => 10000], $response->getData());

	}//end testPublishBudgetResultsReturnsRawPayload()

	/**
	 * Publishing budget results without a session is a 401.
	 *
	 * @return void
	 */
	public function testPublishBudgetResultsWithoutSessionIsUnauthorized(): void {
		$this->staffGuard->method('currentUid')->willReturn(null);
		$this->publicationService->expects($this->never())->method('publishBudgetResults');

		$response = $this->controller()->publishBudgetResults('b-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Unauthorized'], $response->getData());

	}//end testPublishBudgetResultsWithoutSessionIsUnauthorized()
}//end class
