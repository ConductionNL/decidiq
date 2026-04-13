<?php

/**
 * Unit tests for MinutesController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for MinutesController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
 */
class MinutesControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var MinutesController
     */
    private MinutesController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock MinutesGenerationService.
     *
     * @var MinutesGenerationService&MockObject
     */
    private MinutesGenerationService&MockObject $generationService;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request           = $this->createMock(originalClassName: IRequest::class);
        $this->generationService = $this->createMock(originalClassName: MinutesGenerationService::class);
        $this->groupManager      = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession       = $this->createMock(originalClassName: IUserSession::class);
        $this->container         = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appConfig         = $this->createMock(originalClassName: IAppConfig::class);

        $this->controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->generationService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            container: $this->container,
            appConfig: $this->appConfig,
        );

    }//end setUp()

    /**
     * Test that generateDraft returns preview JSON on success.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftReturnsPreviewJson(): void
    {
        $previewText = 'NOTULEN\nRaadsvergadering 10 april 2025\n...';

        $this->generationService->expects($this->once())
            ->method('generateDraft')
            ->with('minutes-uuid-1')
            ->willReturn($previewText);

        $result = $this->controller->generateDraft('minutes-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: $previewText, actual: $result->getData()['preview']);
        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());

    }//end testGenerateDraftReturnsPreviewJson()

    /**
     * Test that generateDraft returns 404 for invalid minutesId.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftInvalidIdReturns404(): void
    {
        $this->generationService->expects($this->once())
            ->method('generateDraft')
            ->with('non-existent-id')
            ->willThrowException(new \RuntimeException('Notulen object niet gevonden'));

        $result = $this->controller->generateDraft('non-existent-id');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testGenerateDraftInvalidIdReturns404()

    /**
     * Test that generateDraft returns 404 when meeting is not linked.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftMissingMeetingReturns404(): void
    {
        $this->generationService->expects($this->once())
            ->method('generateDraft')
            ->with('minutes-no-meeting')
            ->willThrowException(new \RuntimeException('Geen vergadering gekoppeld'));

        $result = $this->controller->generateDraft('minutes-no-meeting');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());

    }//end testGenerateDraftMissingMeetingReturns404()

    /**
     * Test that generateDraft endpoint requires authentication (spec task 9.3c).
     *
     * The @NoAdminRequired annotation allows any authenticated user.
     * The absence of @PublicPage means Nextcloud's SessionMiddleware enforces
     * authentication at the framework layer, returning HTTP 401 for unauthenticated
     * requests. This cannot be tested at the unit level (middleware is outside the
     * controller); this test verifies the annotations are correctly set to guarantee
     * the framework guard fires.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftRequiresAuthentication(): void
    {
        $reflection = new \ReflectionMethod(MinutesController::class, 'generateDraft');
        $docComment = (string) $reflection->getDocComment();

        // @NoAdminRequired: any authenticated user may call this endpoint (not admin-only).
        self::assertStringContainsString('@NoAdminRequired', $docComment);

        // @PublicPage would bypass session auth — its absence means the framework
        // SessionMiddleware enforces authentication and returns 401 for unauthenticated requests.
        self::assertStringNotContainsString('@PublicPage', $docComment);

    }//end testGenerateDraftRequiresAuthentication()

    /**
     * Test that transition() rejects an unknown lifecycle value with 400.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function testTransitionRejectsInvalidLifecycle(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('lifecycle')
            ->willReturn('hacked');

        $result = $this->controller->transition('minutes-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testTransitionRejectsInvalidLifecycle()

    /**
     * Test that transition() returns 403 when a non-admin requests a restricted lifecycle.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function testTransitionRejectsNonAdminForRestrictedLifecycle(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('lifecycle')
            ->willReturn('approved');

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('user1')->willReturn(false);

        $result = $this->controller->transition('minutes-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());

    }//end testTransitionRejectsNonAdminForRestrictedLifecycle()

    /**
     * Test that transition() sets approvedAt, increments version, and populates signedBy on approved.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function testTransitionApprovedSetsApprovedAtVersionAndSignedBy(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('lifecycle')
            ->willReturn('approved');

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin1');
        $user->method('getDisplayName')->willReturn('Admin User');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin1')->willReturn(true);

        $minutesData   = ['id' => 'minutes-uuid-1', 'lifecycle' => 'review', 'version' => 1, 'signedBy' => []];
        $capturedArgs  = [];
        $objectService = new class($minutesData, $capturedArgs) {
            public function __construct(
                private array $minutes,
                private array &$captured,
            ) {
            }

            public function findObject(string $register, string $schema, string $id): ?array
            {
                return $this->minutes;
            }

            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->captured = $object;
                return $object;
            }
        };

        $this->container->method('get')->willReturn($objectService);
        $this->appConfig->method('getValueString')->willReturn('decidesk');

        $result = $this->controller->transition('minutes-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());

        $data = $result->getData();
        self::assertSame(expected: 'approved', actual: $data['lifecycle']);
        self::assertArrayHasKey(key: 'approvedAt', array: $data);
        self::assertSame(expected: 2, actual: $data['version']);
        self::assertContains(needle: 'Admin User', haystack: $data['signedBy']);

    }//end testTransitionApprovedSetsApprovedAtVersionAndSignedBy()
}//end class
