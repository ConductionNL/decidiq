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
 * Tests for DecisionController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
class DecisionControllerTest extends TestCase
{

    /**
     * The controller under test (authenticated admin user).
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
     * Mock ContainerInterface (DI container).
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
     * Mock IUser (authenticated user).
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->user          = $this->createMock(IUser::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new DecisionController(
            request: $this->request,
            container: $this->container,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * publish() for an unauthenticated request returns 401.
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

        // Container must NOT be called for an unauthenticated request.
        $this->container->expects($this->never())->method('get');

        $result = $unauthController->publish('decision-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishUnauthenticatedReturns401()

    /**
     * publish() by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(false);

        // Container must NOT be called — admin check happens before delegation.
        $this->container->expects($this->never())->method('get');

        $result = $this->controller->publish('decision-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishByNonAdminReturns403()

    /**
     * publish() when OpenRegister is unavailable returns 503.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishWhenOpenRegisterUnavailableReturns503(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willThrowException(new \RuntimeException('OpenRegister is not available.'));

        $result = $this->controller->publish('decision-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishWhenOpenRegisterUnavailableReturns503()

    /**
     * publish() when the Decision object is not found returns 404.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishDecisionNotFoundReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        // find() returns null — decision does not exist.
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller->publish('nonexistent-uuid');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishDecisionNotFoundReturns404()

    /**
     * publish() for a decision with a non-adopted outcome returns 422.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishRejectedDecisionReturns422(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-002',
            'outcome'     => 'rejected',
            'isPublished' => false,
        ]);

        $this->objectService->method('find')->willReturn($entity);

        $result = $this->controller->publish('decision-uuid-002');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishRejectedDecisionReturns422()

    /**
     * publish() for an already-published decision returns 422.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishAlreadyPublishedDecisionReturns422(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-003',
            'outcome'     => 'adopted',
            'isPublished' => true,
        ]);

        $this->objectService->method('find')->willReturn($entity);

        $result = $this->controller->publish('decision-uuid-003');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishAlreadyPublishedDecisionReturns422()

    /**
     * publish() happy path returns 200 with the updated Decision.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishSucceedsReturns200(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-004',
            'title'       => 'Besluit A',
            'outcome'     => 'adopted',
            'isPublished' => false,
        ]);

        $this->objectService->method('find')->willReturn($entity);

        $savedData = [
            'id'          => 'decision-uuid-004',
            'isPublished' => true,
            'publishedAt' => '2026-04-14T00:00:00+00:00',
        ];

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($savedData);

        $result = $this->controller->publish('decision-uuid-004');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertArrayHasKey('isPublished', $result->getData());
        self::assertTrue($result->getData()['isPublished']);

    }//end testPublishSucceedsReturns200()

    /**
     * publish() when saveObject throws returns 503.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     *
     * @return void
     */
    public function testPublishWhenSaveFailsReturns503(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn([
            'id'          => 'decision-uuid-005',
            'outcome'     => 'adopted',
            'isPublished' => false,
        ]);

        $this->objectService->method('find')->willReturn($entity);

        $this->objectService->method('saveObject')
            ->willThrowException(new \RuntimeException('Database connection lost.'));

        $result = $this->controller->publish('decision-uuid-005');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testPublishWhenSaveFailsReturns503()

}//end class
