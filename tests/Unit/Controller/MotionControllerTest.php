<?php

/**
 * Unit tests for MotionController — auth guard and attribute assertions.
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
// SPDX-License-Identifier: EUPL-1.2.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MotionController;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MotionController auth guards.
 *
 * Every state-changing endpoint must return 401 for unauthenticated requests
 * and carry the #[NoAdminRequired] PHP attribute so that NC middleware
 * resolves auth before CSRF in authenticated scenarios.
 */
class MotionControllerTest extends TestCase
{

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
     * Mock IUserSession — unauthenticated (getUser returns null).
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $unauthSession;

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
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->motionService = $this->createMock(MotionService::class);
        $this->unauthSession = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);

        $this->unauthSession->method('getUser')->willReturn(null);

    }//end setUp()

    /**
     * Build a MotionController with the given user session.
     *
     * @param IUserSession $session The session to inject.
     *
     * @return MotionController
     */
    private function buildController(IUserSession $session): MotionController
    {
        $participantResolver = $this->createMock(ParticipantResolver::class);

        return new MotionController(
            request: $this->request,
            motionService: $this->motionService,
            userSession: $session,
            groupManager: $this->groupManager,
            appConfig: $this->appConfig,
            participantResolver: $participantResolver,
        );

    }//end buildController()

    /**
     * transition() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testTransitionUnauthenticatedReturns401(): void
    {
        $this->motionService->expects($this->never())->method('transitionLifecycle');

        $result = $this->buildController($this->unauthSession)->transition('motion-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testTransitionUnauthenticatedReturns401()

    /**
     * coSignRequest() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testCoSignRequestUnauthenticatedReturns401(): void
    {
        $this->motionService->expects($this->never())->method('requestCoSignature');

        $result = $this->buildController($this->unauthSession)->coSignRequest('motion-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testCoSignRequestUnauthenticatedReturns401()

    /**
     * coSignConfirm() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testCoSignConfirmUnauthenticatedReturns401(): void
    {
        $this->motionService->expects($this->never())->method('addCoSigner');

        $result = $this->buildController($this->unauthSession)->coSignConfirm('motion-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testCoSignConfirmUnauthenticatedReturns401()

    /**
     * budgetImpact() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testBudgetImpactUnauthenticatedReturns401(): void
    {
        $this->motionService->expects($this->never())->method('saveBudgetImpact');

        $result = $this->buildController($this->unauthSession)->budgetImpact('motion-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testBudgetImpactUnauthenticatedReturns401()

    /**
     * amendmentTransition() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testAmendmentTransitionUnauthenticatedReturns401(): void
    {
        $this->motionService->expects($this->never())->method('transitionLifecycle');

        $result = $this->buildController($this->unauthSession)->amendmentTransition('amendment-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testAmendmentTransitionUnauthenticatedReturns401()

    /**
     * forward() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testForwardUnauthenticatedReturns401(): void
    {
        $this->motionService->expects($this->never())->method('forwardMotion');

        $result = $this->buildController($this->unauthSession)->forward('motion-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testForwardUnauthenticatedReturns401()

    /**
     * All public action methods carry the #[NoAdminRequired] PHP attribute.
     *
     * Without this attribute, NC middleware may block non-admin authenticated
     * users before the controller method is invoked (NC 28+).
     *
     * @return void
     */
    public function testAllActionMethodsHaveNoAdminRequiredAttribute(): void
    {
        $methods = [
            'transition',
            'coSignRequest',
            'coSignConfirm',
            'budgetImpact',
            'amendmentTransition',
            'forward',
        ];

        $ref = new \ReflectionClass(MotionController::class);

        foreach ($methods as $methodName) {
            $method     = $ref->getMethod($methodName);
            $attributes = $method->getAttributes(NoAdminRequired::class);
            self::assertNotEmpty(
                $attributes,
                "MotionController::{$methodName}() must carry #[NoAdminRequired]"
            );
        }

    }//end testAllActionMethodsHaveNoAdminRequiredAttribute()
}//end class
