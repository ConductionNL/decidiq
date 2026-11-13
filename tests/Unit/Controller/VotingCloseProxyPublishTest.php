<?php

/**
 * Unit tests for VotingController close / publish / proxy / revokeProxy bodies.
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

use InvalidArgumentException;
use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of the authorised paths of close, publish, proxy and revokeProxy.
 *
 * The guard is mocked (it is a boundary that resolves roles); the error
 * responder is real so the exception-to-status mapping is exercised.
 *
 * @covers \OCA\Decidiq\Controller\VotingController
 * @uses   \OCA\Decidiq\Service\VotingErrorResponder
 */
class VotingCloseProxyPublishTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock VotingService.
	 *
	 * @var VotingService&MockObject
	 */
	private VotingService&MockObject $votingService;

	/**
	 * Mock OriPublicationService.
	 *
	 * @var OriPublicationService&MockObject
	 */
	private OriPublicationService&MockObject $oriService;

	/**
	 * Mock IUserSession (signed in as "alice").
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock VotingRoundGuard.
	 *
	 * @var VotingRoundGuard&MockObject
	 */
	private VotingRoundGuard&MockObject $guard;

	/**
	 * Mock ProxyDelegationService.
	 *
	 * @var ProxyDelegationService&MockObject
	 */
	private ProxyDelegationService&MockObject $proxyService;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->votingService = $this->createMock(VotingService::class);
		$this->oriService = $this->createMock(OriPublicationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(VotingRoundGuard::class);
		$this->proxyService = $this->createMock(ProxyDelegationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->guard->method('resolveMeetingIdFromVotingRound')->with('round-1')->willReturn('meeting-1');

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return VotingController
	 */
	private function controller(): VotingController {
		return new VotingController(
			request: $this->request,
			votingService: $this->votingService,
			oriService: $this->oriService,
			userSession: $this->userSession,
			guard: $this->guard,
			openHandler: $this->createMock(VotingOpenRequestHandler::class),
			proxyService: $this->proxyService,
			errors: new VotingErrorResponder($this->logger),
		);

	}//end controller()

	/**
	 * Let the chair-or-secretary guard pass.
	 *
	 * @return void
	 */
	private function allowPresiding(): void {
		$this->guard->method('requireChairOrSecretary')->with('meeting-1')->willReturn(null);

	}//end allowPresiding()

	/**
	 * Close without flags uses the plain close with no casting vote.
	 *
	 * @return void
	 */
	public function testCloseWithoutFlagsClosesPlainly(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn([]);
		$this->guard->expects($this->never())->method('requireChair');
		$this->votingService->expects($this->never())->method('closeVotingRoundAnonymised');
		$this->votingService->expects($this->once())
			->method('closeVotingRound')
			->with('round-1', null)
			->willReturn(['id' => 'round-1', 'status' => 'closed']);

		$response = $this->controller()->close('round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'round-1', 'status' => 'closed'], $response->getData());

	}//end testCloseWithoutFlagsClosesPlainly()

	/**
	 * Only a strict boolean true selects the anonymising close.
	 *
	 * @return void
	 */
	public function testCloseWithAnonymiseTrueUsesAnonymisedClose(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['anonymise' => true]);
		$this->votingService->expects($this->never())->method('closeVotingRound');
		$this->votingService->expects($this->once())
			->method('closeVotingRoundAnonymised')
			->with('round-1', null)
			->willReturn(['id' => 'round-1', 'anonymised' => true]);

		$response = $this->controller()->close('round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'round-1', 'anonymised' => true], $response->getData());

	}//end testCloseWithAnonymiseTrueUsesAnonymisedClose()

	/**
	 * A truthy but non-boolean anonymise flag does not anonymise.
	 *
	 * @return void
	 */
	public function testCloseWithStringAnonymiseDoesNotAnonymise(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['anonymise' => 'true']);
		$this->votingService->expects($this->never())->method('closeVotingRoundAnonymised');
		$this->votingService->expects($this->once())
			->method('closeVotingRound')
			->with('round-1', null)
			->willReturn(['id' => 'round-1']);

		self::assertSame(Http::STATUS_OK, $this->controller()->close('round-1')->getStatus());

	}//end testCloseWithStringAnonymiseDoesNotAnonymise()

	/**
	 * A casting vote by a non-chair returns the chair guard's refusal.
	 *
	 * @return void
	 */
	public function testCloseWithChairCastingRefusedForNonChair(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['chairCasting' => 'for']);
		$refusal = new JSONResponse(['message' => 'Chair required'], Http::STATUS_FORBIDDEN);
		$this->guard->expects($this->once())->method('requireChair')->with('meeting-1')->willReturn($refusal);
		$this->votingService->expects($this->never())->method('closeVotingRound');
		$this->votingService->expects($this->never())->method('closeVotingRoundAnonymised');

		$response = $this->controller()->close('round-1');

		self::assertSame($refusal, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCloseWithChairCastingRefusedForNonChair()

	/**
	 * A casting vote other than for/against is a 400.
	 *
	 * @return void
	 */
	public function testCloseWithInvalidChairCastingIsBadRequest(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['chairCasting' => 'abstain']);
		$this->guard->method('requireChair')->willReturn(null);
		$this->votingService->expects($this->never())->method('closeVotingRound');

		$response = $this->controller()->close('round-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'chairCasting must be for or against'], $response->getData());

	}//end testCloseWithInvalidChairCastingIsBadRequest()

	/**
	 * An empty casting vote is ignored (no chair check, null casting).
	 *
	 * @return void
	 */
	public function testCloseWithEmptyChairCastingIsIgnored(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['chairCasting' => '']);
		$this->guard->expects($this->never())->method('requireChair');
		$this->votingService->expects($this->once())
			->method('closeVotingRound')
			->with('round-1', null)
			->willReturn(['id' => 'round-1']);

		self::assertSame(Http::STATUS_OK, $this->controller()->close('round-1')->getStatus());

	}//end testCloseWithEmptyChairCastingIsIgnored()

	/**
	 * A valid casting vote by the chair is passed to the anonymised close.
	 *
	 * @return void
	 */
	public function testCloseWithValidChairCastingPassesItThrough(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['chairCasting' => 'against', 'anonymise' => true]);
		$this->guard->expects($this->once())->method('requireChair')->with('meeting-1')->willReturn(null);
		$this->votingService->expects($this->once())
			->method('closeVotingRoundAnonymised')
			->with('round-1', 'against')
			->willReturn(['id' => 'round-1', 'result' => 'rejected']);

		$response = $this->controller()->close('round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'round-1', 'result' => 'rejected'], $response->getData());

	}//end testCloseWithValidChairCastingPassesItThrough()

	/**
	 * A missing round is a 404.
	 *
	 * @return void
	 */
	public function testCloseOfMissingRoundIsNotFound(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn([]);
		$this->votingService->method('closeVotingRound')
			->willThrowException(new RuntimeException('Voting round not found'));

		$response = $this->controller()->close('round-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Voting round not found'], $response->getData());

	}//end testCloseOfMissingRoundIsNotFound()

	/**
	 * Any other refusal while closing is a 400.
	 *
	 * @return void
	 */
	public function testCloseRefusalIsBadRequest(): void {
		$this->allowPresiding();
		$this->request->method('getParams')->willReturn(['chairCasting' => 'for']);
		$this->guard->method('requireChair')->willReturn(null);
		$this->votingService->method('closeVotingRound')
			->willThrowException(new RuntimeException('Tie-break rule is not chair-decides'));

		$response = $this->controller()->close('round-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Tie-break rule is not chair-decides'], $response->getData());

	}//end testCloseRefusalIsBadRequest()

	/**
	 * Publishing calls ORI and returns the read-back status.
	 *
	 * @return void
	 */
	public function testPublishReturnsPublicationStatus(): void {
		$this->allowPresiding();
		$this->oriService->expects($this->once())->method('publish')->with('round-1');
		$this->oriService->expects($this->once())
			->method('getPublicationStatus')
			->with('round-1')
			->willReturn('published');
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller()->publish('round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'published'], $response->getData());

	}//end testPublishReturnsPublicationStatus()

	/**
	 * A publication failure is logged and answered with an opaque 500.
	 *
	 * @return void
	 */
	public function testPublishFailureIsOpaqueServerError(): void {
		$this->allowPresiding();
		$this->oriService->method('publish')->willThrowException(new RuntimeException('ORI down: secret host'));
		$this->oriService->expects($this->never())->method('getPublicationStatus');
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Decidiq: ORI publication failed',
				['votingRoundId' => 'round-1', 'error' => 'ORI down: secret host']
			);

		$response = $this->controller()->publish('round-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Publication failed'], $response->getData());

	}//end testPublishFailureIsOpaqueServerError()

	/**
	 * A user without participant profile cannot grant a proxy.
	 *
	 * @return void
	 */
	public function testProxyWithoutParticipantProfileIsForbidden(): void {
		$this->votingService->expects($this->once())->method('resolveParticipantUuid')->with('alice')->willReturn(null);
		$this->proxyService->expects($this->never())->method('grantProxy');

		$response = $this->controller()->proxy('round-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Geen deelnemersprofiel gevonden'], $response->getData());

	}//end testProxyWithoutParticipantProfileIsForbidden()

	/**
	 * A proxy without a target participant is a 400.
	 *
	 * @return void
	 */
	public function testProxyWithoutTargetIsBadRequest(): void {
		$this->votingService->method('resolveParticipantUuid')->willReturn('p-alice');
		$this->request->method('getParams')->willReturn([]);
		$this->proxyService->expects($this->never())->method('grantProxy');

		$response = $this->controller()->proxy('round-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'toParticipantId is required'], $response->getData());

	}//end testProxyWithoutTargetIsBadRequest()

	/**
	 * A proxy is granted from the session's participant, never a request value.
	 *
	 * @return void
	 */
	public function testProxyGrantsFromSessionParticipant(): void {
		$this->votingService->method('resolveParticipantUuid')->with('alice')->willReturn('p-alice');
		$this->request->method('getParams')->willReturn(
			['fromParticipantId' => 'p-mallory', 'toParticipantId' => 'p-bob']
		);
		$this->proxyService->expects($this->once())
			->method('grantProxy')
			->with('round-1', 'p-alice', 'p-bob');

		$response = $this->controller()->proxy('round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true], $response->getData());

	}//end testProxyGrantsFromSessionParticipant()

	/**
	 * A validation error while granting is a 400.
	 *
	 * @return void
	 */
	public function testProxyValidationErrorIsBadRequest(): void {
		$this->votingService->method('resolveParticipantUuid')->willReturn('p-alice');
		$this->request->method('getParams')->willReturn(['toParticipantId' => 'p-alice']);
		$this->proxyService->method('grantProxy')
			->willThrowException(new InvalidArgumentException('Cannot delegate to yourself'));

		$response = $this->controller()->proxy('round-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Cannot delegate to yourself'], $response->getData());

	}//end testProxyValidationErrorIsBadRequest()

	/**
	 * A missing target while granting is a 404.
	 *
	 * @return void
	 */
	public function testProxyMissingTargetIsNotFound(): void {
		$this->votingService->method('resolveParticipantUuid')->willReturn('p-alice');
		$this->request->method('getParams')->willReturn(['toParticipantId' => 'p-ghost']);
		$this->proxyService->method('grantProxy')
			->willThrowException(new RuntimeException('Voting round not found'));

		$response = $this->controller()->proxy('round-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Voting round not found'], $response->getData());

	}//end testProxyMissingTargetIsNotFound()

	/**
	 * A user without participant profile cannot revoke a proxy.
	 *
	 * @return void
	 */
	public function testRevokeProxyWithoutParticipantProfileIsForbidden(): void {
		$this->votingService->method('resolveParticipantUuid')->with('alice')->willReturn(null);
		$this->proxyService->expects($this->never())->method('revokeProxy');

		$response = $this->controller()->revokeProxy('round-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Geen deelnemersprofiel gevonden'], $response->getData());

	}//end testRevokeProxyWithoutParticipantProfileIsForbidden()

	/**
	 * Revoking uses the session's participant id.
	 *
	 * @return void
	 */
	public function testRevokeProxyRevokesForSessionParticipant(): void {
		$this->votingService->method('resolveParticipantUuid')->with('alice')->willReturn('p-alice');
		$this->proxyService->expects($this->once())->method('revokeProxy')->with('round-1', 'p-alice');

		$response = $this->controller()->revokeProxy('round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true], $response->getData());

	}//end testRevokeProxyRevokesForSessionParticipant()

	/**
	 * A refusal while revoking is a 400.
	 *
	 * @return void
	 */
	public function testRevokeProxyRefusalIsBadRequest(): void {
		$this->votingService->method('resolveParticipantUuid')->willReturn('p-alice');
		$this->proxyService->method('revokeProxy')
			->willThrowException(new RuntimeException('No active proxy to revoke'));

		$response = $this->controller()->revokeProxy('round-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'No active proxy to revoke'], $response->getData());

	}//end testRevokeProxyRefusalIsBadRequest()
}//end class
