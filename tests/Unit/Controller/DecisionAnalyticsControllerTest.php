<?php

/**
 * Unit tests for DecisionAnalyticsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\DecisionAnalyticsController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionAnalyticsController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
 */
class DecisionAnalyticsControllerTest extends TestCase
{

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
     * Mock ICache.
     *
     * @var ICache&MockObject
     */
    private ICache&MockObject $cache;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock IUser (authenticated user).
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $user;

    /**
     * Controller under test.
     *
     * @var DecisionAnalyticsController
     */
    private DecisionAnalyticsController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->cache       = $this->createMock(ICache::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->user        = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new DecisionAnalyticsController(
            appName: 'decidesk',
            request: $this->request,
            container: $this->container,
            cache: $this->cache,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * analytics() returns cached data when cache hit.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     *
     * @return void
     */
    public function testAnalyticsReturnsCachedDataOnCacheHit(): void
    {
        $cachedData = ['decisionsPerMonth' => [], 'overdueActionItems' => 3];
        $this->cache->method('get')->willReturn(json_encode($cachedData));

        $result = $this->controller->analytics();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame(3, $result->getData()['overdueActionItems']);

    }//end testAnalyticsReturnsCachedDataOnCacheHit()

    /**
     * analytics() returns 500 when ObjectService is unavailable.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     *
     * @return void
     */
    public function testAnalyticsReturns500WhenObjectServiceUnavailable(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->container->method('get')
            ->willThrowException(new \Exception('Service not available'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->analytics();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());

    }//end testAnalyticsReturns500WhenObjectServiceUnavailable()

    /**
     * analytics() computes and returns fresh data when cache is empty.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     *
     * @return void
     */
    public function testAnalyticsReturnsFreshDataWhenCacheMiss(): void
    {
        $objectService = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
            ->getMock();

        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturn([]);

        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())->method('set');

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->controller->analytics();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertArrayHasKey('decisionsPerMonth', $result->getData());
        self::assertArrayHasKey('pendingApprovals', $result->getData());
        self::assertArrayHasKey('overdueActionItems', $result->getData());

    }//end testAnalyticsReturnsFreshDataWhenCacheMiss()

    /**
     * analytics() returns 401 when user is not authenticated.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     *
     * @return void
     */
    public function testAnalyticsReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new DecisionAnalyticsController(
            appName: 'decidesk',
            request: $this->request,
            container: $this->container,
            cache: $this->cache,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $result = $unauthController->analytics();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testAnalyticsReturns401WhenNotAuthenticated()

    /**
     * analytics() returns 403 when user is not a member of the requested governance body.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     *
     * @return void
     */
    public function testAnalyticsReturns403WhenNotGovernanceBodyMember(): void
    {
        $objectService = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
            ->getMock();

        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        // findAll returns empty array — user is not a member.
        $objectService->method('findAll')->willReturn([]);

        $this->cache->method('get')->willReturn(null);

        $this->container->method('get')
            ->willReturn($objectService);

        $result = $this->controller->analytics(governanceBodyId: 'body-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testAnalyticsReturns403WhenNotGovernanceBodyMember()

}//end class
