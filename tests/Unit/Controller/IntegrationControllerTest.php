<?php

/**
 * Wire-contract tests for the contract-decision-hub integration endpoints.
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

use OCA\Decidesk\Controller\IntegrationController;
use OCA\Decidesk\Service\DecisionIntegrationService;
use OCP\AppFramework\Http;
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
class IntegrationControllerTest extends TestCase
{

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
     * The controller under test.
     *
     * @var IntegrationController
     */
    private IntegrationController $controller;


    /**
     * Set up mocks and the controller.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request            = $this->createMock(IRequest::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->integrationService = $this->createMock(DecisionIntegrationService::class);

        $this->controller = new IntegrationController(
            $this->request,
            $this->userSession,
            $this->integrationService,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * Sign a user into the mocked session.
     *
     * @param string $uid The Nextcloud uid.
     *
     * @return void
     */
    private function signIn(string $uid='fleet-app'): void
    {
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
    public function testGetOutcomeWithoutSessionIs401(): void
    {
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
    public function testGetOutcomeReturnsEnvelope(): void
    {
        $this->signIn();

        $envelope = [
            'decisionId'        => 'decision-1',
            'decisionType'      => 'contract',
            'status'            => 'approved',
            'decidedAt'         => '2026-08-01T12:00:00+00:00',
            'signed'            => true,
            'signingReference'  => 'eidas-ref-9',
            'signedAt'          => '2026-08-01T12:05:00+00:00',
            'signers'           => ['voorzitter'],
            'subjectRegister'   => 'contracts',
            'subjectSchema'     => 'contract',
            'subjectId'         => 'contract-77',
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
    public function testGetOutcomeUnknownDecisionIs404(): void
    {
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
    public function testGetOutcomeServiceFailureIs500WithoutLeakingDetail(): void
    {
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
    public function testSubscribeWithoutSessionIs401(): void
    {
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
    public function testSubscribeWithoutCallbackUrlIs400(): void
    {
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
    public function testSubscribeReturns201WithSubscriptionId(): void
    {
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
    public function testSubscribeRejectsUnregisteredCallbackWith403(): void
    {
        $this->signIn();
        $this->request->method('getParam')->with('callbackUrl', '')
            ->willReturn('http://169.254.169.254/latest/meta-data/');

        $this->integrationService->method('registerOutcomeCallback')->willReturn(
            [
                'success' => false,
                'code'    => 'ssrf_rejected',
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
    public function testSubscribeUnknownDecisionIs404(): void
    {
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
    public function testSubscribeOtherRejectionIs422(): void
    {
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


}//end class
