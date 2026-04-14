<?php

/**
 * Unit tests for DecisionController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\DecisionController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionController::publish().
 *
 * Covers all five distinct code paths: 401, 403, 404, 422, 200, and 503.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
class DecisionControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var DecisionController
     */
    private DecisionController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

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
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock IUser (authenticated admin).
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $user;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Set up test fixtures for an authenticated admin user.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request      = $this->createMock(IRequest::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->user         = $this->createMock(IUser::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->user->method('getUID')->willReturn('adminuser');
        $this->userSession->method('getUser')->willReturn($this->user);
        $this->groupManager->method('isAdmin')->with('adminuser')->willReturn(true);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->controller = new DecisionController(
            request: $this->request,
            container: $this->container,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * publish() returns 401 when the user is not authenticated.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishUnauthenticatedReturns401(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new DecisionController(
            request: $this->request,
            container: $this->container,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );

        $result = $unauthController->publish('decision-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishUnauthenticatedReturns401()

    /**
     * publish() returns 403 when the caller is not a Nextcloud administrator.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishByNonAdminReturns403(): void
    {
        $nonAdminGroupManager = $this->createMock(IGroupManager::class);
        $nonAdminGroupManager->method('isAdmin')->with('adminuser')->willReturn(false);

        $nonAdminController = new DecisionController(
            request: $this->request,
            container: $this->container,
            userSession: $this->userSession,
            groupManager: $nonAdminGroupManager,
            logger: $this->logger,
        );

        $result = $nonAdminController->publish('decision-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishByNonAdminReturns403()

    /**
     * publish() returns 503 when OpenRegister is not available.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishWhenOpenRegisterUnavailableReturns503(): void
    {
        $unavailableContainer = $this->createMock(ContainerInterface::class);
        $unavailableContainer->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willThrowException(new \RuntimeException('OpenRegister not available'));

        $unavailableController = new DecisionController(
            request: $this->request,
            container: $unavailableContainer,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );

        $result = $unavailableController->publish('decision-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishWhenOpenRegisterUnavailableReturns503()

    /**
     * publish() returns 404 when the Decision object is not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishDecisionNotFoundReturns404(): void
    {
        $this->objectService->method('find')->with(id: 'nonexistent-uuid')->willReturn(null);

        $result = $this->controller->publish('nonexistent-uuid');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishDecisionNotFoundReturns404()

    /**
     * publish() returns 422 when the decision outcome is not "adopted".
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishRejectedDecisionReturns422(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-002',
            'outcome'     => 'rejected',
            'isPublished' => false,
        ]);
        $this->objectService->method('find')->with(id: 'decision-uuid-002')->willReturn($entity);

        $result = $this->controller->publish('decision-uuid-002');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishRejectedDecisionReturns422()

    /**
     * publish() returns 422 when the decision is already published.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishAlreadyPublishedDecisionReturns422(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-003',
            'outcome'     => 'adopted',
            'isPublished' => true,
            'publishedAt' => '2026-01-01T00:00:00+00:00',
        ]);
        $this->objectService->method('find')->with(id: 'decision-uuid-003')->willReturn($entity);

        $result = $this->controller->publish('decision-uuid-003');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishAlreadyPublishedDecisionReturns422()

    /**
     * publish() returns 200 with the updated Decision object on success.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishSucceedsReturns200(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-004',
            'outcome'     => 'adopted',
            'isPublished' => false,
        ]);
        $this->objectService->method('find')->with(id: 'decision-uuid-004')->willReturn($entity);

        $saved = new \stdClass();
        $saved->id          = 'decision-uuid-004';
        $saved->outcome     = 'adopted';
        $saved->isPublished = true;
        $saved->publishedAt = '2026-04-14T19:00:00+00:00';
        $this->objectService->method('saveObject')->willReturn($saved);

        $result = $this->controller->publish('decision-uuid-004');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertTrue($result->getData()['isPublished']);

    }//end testPublishSucceedsReturns200()

    /**
     * publish() returns 503 when saveObject throws an exception.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishWhenSaveFailsReturns503(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-005',
            'outcome'     => 'adopted',
            'isPublished' => false,
        ]);
        $this->objectService->method('find')->with(id: 'decision-uuid-005')->willReturn($entity);
        $this->objectService->method('saveObject')
            ->willThrowException(new \RuntimeException('Database error'));

        $result = $this->controller->publish('decision-uuid-005');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishWhenSaveFailsReturns503()

}//end class
