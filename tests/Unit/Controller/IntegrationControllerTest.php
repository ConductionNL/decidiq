<?php

/**
 * Wire-contract tests for the contract-decision-hub integration endpoints.
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

use OCA\Decidiq\Controller\IntegrationController;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for the two ADR-019 integration-surface reads/writes:
 *
 *   GET  /api/v1/decisions/{id}/outcome
 *   POST /api/v1/decisions/{id}/subscriptions
 *
 * Both are consumed by OTHER fleet apps over HTTP, so the response envelope is
 * a published interface, not an implementation detail. Two invariants get
 * asserted beyond the status codes.
 *
 * The outcome envelope's key set is pinned. A caller reads `status` to decide
 * whether a contract may proceed; silently renaming or dropping that key turns
 * every consumer's check into a read of `null`, which is falsy, which reads as
 * "not approved" — a failure that never raises anything anywhere.
 *
 * The subscribe route's SSRF rejection is pinned to 403 and kept apart from the
 * 404 (unknown decision) and the 422 (everything else). A callback URL that
 * does not match a registered registry consumer must be refused distinguishably;
 * folding it into the generic 422 makes an anti-SSRF failure look like a typo
 * in the request body.
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */
class IntegrationControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock DecisionIntegrationService.
	 *
	 * @var DecisionIntegrationService&MockObject
	 */
	private DecisionIntegrationService&MockObject $integrationService;

	/**
	 * Mock DecisionIntegrationAuthorizationGuard (REQ-DCDH-101 / REQ-DCDH-102).
	 *
	 * @var DecisionIntegrationAuthorizationGuard&MockObject
	 */
	private DecisionIntegrationAuthorizationGuard&MockObject $authorizationGuard;

	/**
	 * Mock IGroupManager (admin bypass on the outcome-read guard).
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var IntegrationController
	 */
	private IntegrationController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * The outcome-read guard (REQ-DCDH-101) and the subscribe guard
	 * (REQ-DCDH-102) both default to ALLOW here so the pre-existing
	 * envelope/404/500/201 contract tests keep asserting what they were written
	 * to assert. Each guard's own allow/deny matrix is proven by the dedicated
	 * tests below, which override these defaults explicitly.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->integrationService = $this->createMock(DecisionIntegrationService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->authorizationGuard = $this->createMock(DecisionIntegrationAuthorizationGuard::class);
		$this->authorizationGuard->method('isAuthorizedToReadOutcome')->willReturn(true);
		$this->authorizationGuard->method('isAuthorizedToSubscribe')->willReturn(true);

		$this->controller = new IntegrationController(
			$this->request,
			$this->userSession,
			$this->integrationService,
			$this->createMock(LoggerInterface::class),
			$this->groupManager,
			$this->authorizationGuard,
		);

	}//end setUp()

	/**
	 * Build a controller whose outcome-read guard and admin verdict are set
	 * explicitly. Used by the REQ-DCDH-101 tests, which must control both.
	 *
	 * @param bool $authorized What `isAuthorizedToReadOutcome()` answers
	 * @param bool $isAdmin Whether the caller is a Nextcloud administrator
	 * @param array<string, mixed>|null $envelope What `getOutcomeEnvelope()` returns when reached
	 * @param bool $envelopeExpected Whether the envelope assembler must be reached at all
	 *
	 * @return IntegrationController
	 */
	private function makeGuardedController(
		bool $authorized,
		bool $isAdmin = false,
		?array $envelope = ['decisionId' => 'decision-1', 'status' => 'approved'],
		bool $envelopeExpected = true,
	): IntegrationController {
		$service = $this->createMock(DecisionIntegrationService::class);
		$guard = $this->createMock(DecisionIntegrationAuthorizationGuard::class);
		$guard->method('isAuthorizedToReadOutcome')->willReturn($authorized);

		if ($envelopeExpected === true) {
			$service->expects($this->once())->method('getOutcomeEnvelope')->willReturn($envelope);
		} else {
			// A refusal must never reach the assembler — otherwise the envelope
			// was built (and could be logged or cached) for a caller who may
			// not see it.
			$service->expects($this->never())->method('getOutcomeEnvelope');
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('svc-shillinq');
		$session->method('getUser')->willReturn($user);

		return new IntegrationController(
			$this->createMock(IRequest::class),
			$session,
			$service,
			$this->createMock(LoggerInterface::class),
			$groupManager,
			$guard,
		);
	}//end makeGuardedController()

	/**
	 * REQ-DCDH-101 (allow): the consumer that raised the Decision — its
	 * OpenRegister `@self.owner` — still gets the envelope. This is the caller
	 * REQ-DCDH-003 exists to serve, so the guard must not close the endpoint's
	 * only real use case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function testGetOutcomeAllowsTheRaisingConsumer(): void {
		$controller = $this->makeGuardedController(authorized: true);

		$response = $controller->getOutcome(id: 'decision-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('approved', $response->getData()['status']);

	}//end testGetOutcomeAllowsTheRaisingConsumer()

	/**
	 * REQ-DCDH-101 (deny): an authenticated caller who neither raised the
	 * Decision nor is an admin, on a Decision that is not published, is refused
	 * with 403 — and the envelope is never assembled.
	 *
	 * Without this guard, enumerating Decision UUIDs disclosed the cross-app
	 * subject coordinates, the consumer's externalReference, and the signers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function testGetOutcomeDeniesAnUnrelatedCallerWith403(): void {
		$controller = $this->makeGuardedController(authorized: false, envelopeExpected: false);

		$response = $controller->getOutcome(id: 'decision-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('Forbidden.', $response->getData()['message']);

	}//end testGetOutcomeDeniesAnUnrelatedCallerWith403()

	/**
	 * REQ-DCDH-101 (allow): a Nextcloud administrator bypasses the guard —
	 * `resolveCallerUid()` returns null, so `isAuthorizedToReadOutcome()` is
	 * never consulted at all. Proven by making the guard answer DENY and
	 * asserting the read succeeds anyway: if the bypass were missing, this test
	 * would see a 403.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function testGetOutcomeAllowsAnAdministratorViaTheBypass(): void {
		$controller = $this->makeGuardedController(authorized: false, isAdmin: true);

		$response = $controller->getOutcome(id: 'decision-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testGetOutcomeAllowsAnAdministratorViaTheBypass()

	/**
	 * REQ-DCDH-101: an authorized caller asking for a Decision that does not
	 * exist still gets 404, not 403. The guard allows a miss through so a 403
	 * cannot become an existence oracle for UUIDs the app never issued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function testGetOutcomeMissingDecisionStays404NotForbidden(): void {
		$controller = $this->makeGuardedController(authorized: true, envelope: null);

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$controller->getOutcome(id: 'ghost')->getStatus()
		);

	}//end testGetOutcomeMissingDecisionStays404NotForbidden()

	/**
	 * Sign a user into the mocked session.
	 *
	 * @param string $uid The Nextcloud uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'fleet-app'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * An anonymous caller gets 401 on the outcome read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testGetOutcomeWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->integrationService->expects($this->never())->method('getOutcomeEnvelope');

		self::assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->getOutcome(id: 'decision-1')->getStatus()
		);

	}//end testGetOutcomeWithoutSessionIs401()

	/**
	 * The outcome envelope comes back verbatim, with the key set consuming
	 * apps depend on intact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testGetOutcomeReturnsEnvelope(): void {
		$this->signIn();

		$envelope = [
			'decisionId' => 'decision-1',
			'decisionType' => 'contract',
			'status' => 'approved',
			'decidedAt' => '2026-08-01T12:00:00+00:00',
			'signed' => true,
			'signingReference' => 'eidas-ref-9',
			'signedAt' => '2026-08-01T12:05:00+00:00',
			'signers' => ['voorzitter'],
			'subjectRegister' => 'contracts',
			'subjectSchema' => 'contract',
			'subjectId' => 'contract-77',
			'externalReference' => 'PO-2026-0042',
		];

		$this->integrationService->expects($this->once())
			->method('getOutcomeEnvelope')
			->with(decisionId: 'decision-1')
			->willReturn($envelope);

		$response = $this->controller->getOutcome(id: 'decision-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($envelope, $response->getData());

		// The published key set is the interface — a consumer reading a renamed
		// or dropped key gets null, which is falsy, which silently reads as
		// "not approved".
		foreach (['decisionId', 'decisionType', 'status', 'decidedAt', 'signed', 'subjectId', 'externalReference'] as $key) {
			self::assertArrayHasKey($key, $response->getData());
		}

	}//end testGetOutcomeReturnsEnvelope()

	/**
	 * A decision the caller cannot read (or that does not exist) is 404 — the
	 * service returns null and no UUID probing is possible.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testGetOutcomeUnknownDecisionIs404(): void {
		$this->signIn();
		$this->integrationService->method('getOutcomeEnvelope')->willReturn(null);

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->getOutcome(id: 'ghost')->getStatus()
		);

	}//end testGetOutcomeUnknownDecisionIs404()

	/**
	 * A service failure is a 500 carrying a generic message — the exception
	 * text is not echoed onto a cross-app integration surface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testGetOutcomeServiceFailureIs500WithoutLeakingDetail(): void {
		$this->signIn();
		$this->integrationService->method('getOutcomeEnvelope')
			->willThrowException(new \RuntimeException('SQLSTATE[08006] connection refused on 10.0.0.5:5432'));

		$response = $this->controller->getOutcome(id: 'decision-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Internal server error.', $response->getData()['message']);

	}//end testGetOutcomeServiceFailureIs500WithoutLeakingDetail()

	/**
	 * An anonymous caller gets 401 on subscribe and no callback is registered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testSubscribeWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->integrationService->expects($this->never())->method('registerOutcomeCallback');

		self::assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->subscribe(id: 'decision-1')->getStatus()
		);

	}//end testSubscribeWithoutSessionIs401()

	/**
	 * A body without `callbackUrl` is 400 before the service runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testSubscribeWithoutCallbackUrlIs400(): void {
		$this->signIn();
		$this->request->method('getParam')->with('callbackUrl', '')->willReturn('');
		$this->integrationService->expects($this->never())->method('registerOutcomeCallback');

		$response = $this->controller->subscribe(id: 'decision-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('callbackUrl', $response->getData()['message']);

	}//end testSubscribeWithoutCallbackUrlIs400()

	/**
	 * A successful subscription answers 201 with the service result, and the
	 * acting uid is the SESSION's — not anything from the body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testSubscribeReturns201WithSubscriptionId(): void {
		$this->signIn(uid: 'pipelinq');
		$this->request->method('getParam')->with('callbackUrl', '')
			->willReturn('https://pipelinq.example/hooks/decision');

		$this->integrationService->expects($this->once())
			->method('registerOutcomeCallback')
			->with(
				decisionId: 'decision-1',
				callbackUrl: 'https://pipelinq.example/hooks/decision',
				actorId: 'pipelinq'
			)
			->willReturn(['success' => true, 'subscriptionId' => 'sub-3']);

		$response = $this->controller->subscribe(id: 'decision-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('sub-3', $response->getData()['subscriptionId']);

	}//end testSubscribeReturns201WithSubscriptionId()

	/**
	 * A callback URL that matches no registered registry consumer is 403 — the
	 * anti-SSRF refusal, kept distinguishable from a malformed request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testSubscribeRejectsUnregisteredCallbackWith403(): void {
		$this->signIn();
		$this->request->method('getParam')->with('callbackUrl', '')
			->willReturn('http://169.254.169.254/latest/meta-data/');

		$this->integrationService->method('registerOutcomeCallback')->willReturn(
			[
				'success' => false,
				'code' => 'ssrf_rejected',
				'message' => 'Callback URL does not match a registered consumer.',
			]
		);

		$response = $this->controller->subscribe(id: 'decision-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testSubscribeRejectsUnregisteredCallbackWith403()

	/**
	 * An unknown decision on subscribe is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testSubscribeUnknownDecisionIs404(): void {
		$this->signIn();
		$this->request->method('getParam')->with('callbackUrl', '')
			->willReturn('https://pipelinq.example/hooks/decision');
		$this->integrationService->method('registerOutcomeCallback')->willReturn(
			['success' => false, 'code' => 'not_found', 'message' => 'Decision not found.']
		);

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->subscribe(id: 'ghost')->getStatus()
		);

	}//end testSubscribeUnknownDecisionIs404()

	/**
	 * Any other subscription rejection is 422 — the residual bucket, asserted
	 * so that a future code is not silently swallowed into a 403 or 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	public function testSubscribeOtherRejectionIs422(): void {
		$this->signIn();
		$this->request->method('getParam')->with('callbackUrl', '')
			->willReturn('https://pipelinq.example/hooks/decision');
		$this->integrationService->method('registerOutcomeCallback')->willReturn(
			['success' => false, 'code' => 'already_subscribed', 'message' => 'Already subscribed.']
		);

		self::assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->subscribe(id: 'decision-1')->getStatus()
		);

	}//end testSubscribeOtherRejectionIs422()

	/**
	 * Build a controller whose SUBSCRIBE guard and admin verdict are set
	 * explicitly, with a request body that always carries a valid callbackUrl
	 * so nothing but the guard can decide the outcome.
	 *
	 * @param bool $authorized What `isAuthorizedToSubscribe()` answers
	 * @param bool $isAdmin Whether the caller is a Nextcloud administrator
	 * @param bool $writeExpected Whether the write must be reached at all
	 * @param array<string, mixed> $writeResult What `registerOutcomeCallback()` returns when reached
	 *
	 * @return IntegrationController
	 */
	private function makeSubscribeGuardedController(
		bool $authorized,
		bool $isAdmin = false,
		bool $writeExpected = true,
		array $writeResult = ['success' => true, 'subscriptionId' => 'sub-9'],
	): IntegrationController {
		$service = $this->createMock(DecisionIntegrationService::class);
		$guard = $this->createMock(DecisionIntegrationAuthorizationGuard::class);
		$guard->method('isAuthorizedToSubscribe')->willReturn($authorized);

		if ($writeExpected === true) {
			$service->expects($this->once())->method('registerOutcomeCallback')->willReturn($writeResult);
		} else {
			// A refusal must never reach the write. This is the assertion that
			// makes the guard mean something: a 403 that still persisted the
			// callback URL would be a 403 in name only.
			$service->expects($this->never())->method('registerOutcomeCallback');
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('svc-shillinq');
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('callbackUrl', '')
			->willReturn('https://pipelinq.example/hooks/decision');

		return new IntegrationController(
			$request,
			$session,
			$service,
			$this->createMock(LoggerInterface::class),
			$groupManager,
			$guard,
		);
	}//end makeSubscribeGuardedController()

	/**
	 * REQ-DCDH-102 (deny): an authenticated caller who did not raise the
	 * Decision and is not an admin is refused with 403 — and
	 * `registerOutcomeCallback()` is NEVER called, so no callback URL is
	 * written.
	 *
	 * Without this guard any authenticated user could overwrite the raising
	 * consumer's `outcomeCallbackUrl` on any Decision UUID, redirecting the
	 * outcome envelope to another registered consumer and denying the
	 * legitimate one its callback. The anti-SSRF check in the service validates
	 * the URL, never the caller, so it does not close this hole.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function testSubscribeDeniesAnUnrelatedCallerWith403AndNeverWrites(): void {
		$controller = $this->makeSubscribeGuardedController(authorized: false, writeExpected: false);

		$response = $controller->subscribe(id: 'decision-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('Forbidden.', $response->getData()['message']);

	}//end testSubscribeDeniesAnUnrelatedCallerWith403AndNeverWrites()

	/**
	 * REQ-DCDH-102 (deny), stated so that the failure is unmissable. The test
	 * above uses `expects($this->never())`, whose violation is thrown INSIDE the
	 * controller's `catch (\Throwable)` and therefore reaches the assertions
	 * disguised as a 500. This one counts the write with a plain closure that
	 * nothing can swallow, so removing the guard reports the actual defect:
	 * the callback URL was persisted for a caller who may not touch it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function testSubscribeRefusalPersistsNoCallbackUrl(): void {
		$writes = 0;

		$service = $this->createMock(DecisionIntegrationService::class);
		$guard = $this->createMock(DecisionIntegrationAuthorizationGuard::class);
		$guard->method('isAuthorizedToSubscribe')->willReturn(false);
		$service->method('registerOutcomeCallback')->willReturnCallback(
			function () use (&$writes): array {
				$writes++;
				return ['success' => true, 'subscriptionId' => 'sub-leaked'];
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('callbackUrl', '')
			->willReturn('https://mallory.example/hooks/steal');

		$controller = new IntegrationController(
			$request,
			$session,
			$service,
			$this->createMock(LoggerInterface::class),
			$groupManager,
			$guard,
		);

		$controller->subscribe(id: 'decision-1');

		self::assertSame(
			0,
			$writes,
			'A refused subscribe must not persist an outcome callback URL.'
		);

	}//end testSubscribeRefusalPersistsNoCallbackUrl()

	/**
	 * REQ-DCDH-102 (allow): the consumer that raised the Decision still
	 * subscribes successfully — the guard must not close the endpoint's only
	 * real use case (ADR-044).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function testSubscribeAllowsTheRaisingConsumer(): void {
		$controller = $this->makeSubscribeGuardedController(authorized: true);

		$response = $controller->subscribe(id: 'decision-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('sub-9', $response->getData()['subscriptionId']);

	}//end testSubscribeAllowsTheRaisingConsumer()

	/**
	 * REQ-DCDH-102 (allow): a Nextcloud administrator bypasses the guard —
	 * `resolveCallerUid()` returns null, so `isAuthorizedToSubscribe()` is never
	 * consulted. Proven by making the guard answer DENY and asserting the write
	 * happens anyway: if the bypass were missing, this test would see a 403.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function testSubscribeAllowsAnAdministratorViaTheBypass(): void {
		$controller = $this->makeSubscribeGuardedController(authorized: false, isAdmin: true);

		self::assertSame(
			Http::STATUS_CREATED,
			$controller->subscribe(id: 'decision-1')->getStatus()
		);

	}//end testSubscribeAllowsAnAdministratorViaTheBypass()

	/**
	 * REQ-DCDH-102: an authorized caller subscribing to a Decision that does not
	 * exist still gets 404, not 403. The guard allows a miss through so a 403
	 * cannot become an existence oracle for UUIDs the app never issued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function testSubscribeMissingDecisionStays404NotForbidden(): void {
		$controller = $this->makeSubscribeGuardedController(
			authorized: true,
			writeResult: ['success' => false, 'code' => 'not_found', 'message' => 'Decision not found.']
		);

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$controller->subscribe(id: 'ghost')->getStatus()
		);

	}//end testSubscribeMissingDecisionStays404NotForbidden()

}//end class
