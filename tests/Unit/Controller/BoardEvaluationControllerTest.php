<?php

/**
 * Wire-contract tests for the board self-evaluation response endpoint.
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

use OCA\Decidesk\Controller\BoardEvaluationController;
use OCA\Decidesk\Service\BoardEvaluationReportService;
use OCA\Decidesk\Service\BoardEvaluationResponseService;
use OCA\Decidesk\Service\BoardEvaluationScoreService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `POST /api/board-evaluations/{id}/respond`.
 *
 * The endpoint's defining property is that participant identity is derived
 * SERVER-SIDE from the session and never read from the request body. A version
 * that accepted a client-supplied participantId would let any member file a
 * response as any other member — and would look identical on the wire, since
 * the status code and body shape are unchanged. So the test that matters here
 * asserts the id reaching the service is the one resolved from the session, and
 * that a body carrying its own `participantId` cannot displace it.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
 */
class BoardEvaluationControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock BoardEvaluationResponseService.
	 *
	 * @var BoardEvaluationResponseService&MockObject
	 */
	private BoardEvaluationResponseService&MockObject $responseService;

	// NOTE: the NC-uid -> participant-UUID resolution used to come from
	// VotingService and is now BoardEvaluationResponseService::resolveResponder(),
	// because the identity has to be scoped to the evaluation's own governance
	// body. The controller no longer takes VotingService at all, so these tests
	// stub the resolution on $responseService instead. The behaviours asserted
	// below are unchanged.

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var BoardEvaluationController
	 */
	private BoardEvaluationController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->responseService = $this->createMock(BoardEvaluationResponseService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new BoardEvaluationController(
			$this->request,
			$this->responseService,
			$this->createMock(BoardEvaluationScoreService::class),
			$this->createMock(BoardEvaluationReportService::class),
			$this->createMock(ParticipationPublicationService::class),
			$this->userSession,
		);

	}//end setUp()

	/**
	 * Sign a user into the mocked session.
	 *
	 * @param string $uid The Nextcloud uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'bestuurslid'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * An anonymous caller gets 401 and no response is recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testRespondWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->responseService->expects($this->never())->method('submitResponse');

		$response = $this->controller->respond(id: 'evaluation-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRespondWithoutSessionIs401()

	/**
	 * A signed-in user with no participant profile on the board is 403 — the
	 * evaluation is not open to arbitrary instance users.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testRespondWithoutParticipantProfileIs403(): void {
		$this->signIn(uid: 'outsider');
		$this->responseService->method('resolveResponder')
			->with('evaluation-1', 'outsider')->willReturn(null);
		$this->responseService->expects($this->never())->method('submitResponse');

		$response = $this->controller->respond(id: 'evaluation-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testRespondWithoutParticipantProfileIs403()

	/**
	 * A valid submission answers 201 with the stored response under the
	 * `response` key, and the participant id handed to the service is the one
	 * resolved from the SESSION uid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testRespondResolvesParticipantFromSessionAndReturns201(): void {
		$this->signIn(uid: 'bestuurslid');
		$this->responseService->method('resolveResponder')
			->with('evaluation-1', 'bestuurslid')->willReturn('participant-9');

		$answers = [['dimension' => 'strategy', 'score' => 4]];
		$this->request->method('getParam')->with('answers', [])->willReturn($answers);

		$this->responseService->expects($this->once())
			->method('submitResponse')
			->with(evaluationId: 'evaluation-1', participantId: 'participant-9', answers: $answers)
			->willReturn(['success' => true, 'response' => ['id' => 'response-1']]);

		$response = $this->controller->respond(id: 'evaluation-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['id' => 'response-1'], $response->getData());

	}//end testRespondResolvesParticipantFromSessionAndReturns201()

	/**
	 * A `participantId` in the request body is IGNORED — identity comes from
	 * the session only. Without this the anonymity guarantee is decoration:
	 * a member could file a response in someone else's name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testRespondIgnoresClientSuppliedParticipantId(): void {
		$this->signIn(uid: 'bestuurslid');
		$this->responseService->method('resolveResponder')
			->with('evaluation-1', 'bestuurslid')->willReturn('participant-9');

		$this->request->method('getParam')->willReturnMap(
			[
				['answers', [], [['dimension' => 'strategy', 'score' => 1]]],
				['participantId', null, 'participant-OTHER'],
			]
		);

		$this->responseService->expects($this->once())
			->method('submitResponse')
			->with(
				evaluationId: 'evaluation-1',
				participantId: 'participant-9',
				answers: [['dimension' => 'strategy', 'score' => 1]]
			)
			->willReturn(['success' => true, 'response' => ['id' => 'response-1']]);

		self::assertSame(
			Http::STATUS_CREATED,
			$this->controller->respond(id: 'evaluation-1')->getStatus()
		);

	}//end testRespondIgnoresClientSuppliedParticipantId()

	/**
	 * A non-array `answers` payload degrades to an empty array rather than
	 * reaching the service as a scalar and exploding into a 500.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testRespondCoercesNonArrayAnswers(): void {
		$this->signIn(uid: 'bestuurslid');
		$this->responseService->method('resolveResponder')->willReturn('participant-9');
		$this->request->method('getParam')->with('answers', [])->willReturn('not-an-array');

		$this->responseService->expects($this->once())
			->method('submitResponse')
			->with(evaluationId: 'evaluation-1', participantId: 'participant-9', answers: [])
			->willReturn(['success' => false, 'message' => 'At least one answer is required.']);

		$response = $this->controller->respond(id: 'evaluation-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testRespondCoercesNonArrayAnswers()

	/**
	 * An unknown evaluation surfaces as 404, not the generic 422 every other
	 * service rejection maps to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testRespondUnknownEvaluationIs404(): void {
		$this->signIn();
		$this->responseService->method('resolveResponder')->willReturn('participant-9');
		$this->request->method('getParam')->willReturn([]);
		$this->responseService->method('submitResponse')
			->willReturn(['success' => false, 'message' => 'Evaluation not found.']);

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->respond(id: 'ghost')->getStatus()
		);

	}//end testRespondUnknownEvaluationIs404()
}//end class
