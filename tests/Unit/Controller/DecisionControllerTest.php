<?php

/**
 * Unit tests for DecisionController.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\DecisionController;
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
 * Tests for DecisionController::publish().
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
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

        $this->request      = $this->createMock(originalClassName: IRequest::class);
        $this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession  = $this->createMock(originalClassName: IUserSession::class);
        $this->container    = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);

        $this->controller = new DecisionController(
            request: $this->request,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            container: $this->container,
            appConfig: $this->appConfig,
        );

    }//end setUp()

    /**
     * Test that publish() returns 403 when called by a non-admin user.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
     */
    public function testPublishRejectsNonAdmin(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('user1')->willReturn(false);

        $result = $this->controller->publish('decision-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testPublishRejectsNonAdmin()

    /**
     * Test that publish() returns 422 when the decision outcome is not 'adopted'.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
     */
    public function testPublishRejectsNonAdoptedDecision(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin1')->willReturn(true);

        $decisionData  = ['id' => 'decision-uuid-1', 'outcome' => 'rejected'];
        $objectService = new class($decisionData) {
            public function __construct(private array $decision)
            {
            }

            public function findObject(string $register, string $schema, string $id): ?array
            {
                return $this->decision;
            }
        };

        $this->container->method('get')->willReturn($objectService);
        $this->appConfig->method('getValueString')->willReturn('decidesk');

        $result = $this->controller->publish('decision-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $result->getStatus());

    }//end testPublishRejectsNonAdoptedDecision()

    /**
     * Test that publish() returns 404 when the decision object is not found.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
     */
    public function testPublishReturnsNotFoundWhenDecisionMissing(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin1')->willReturn(true);

        $objectService = new class {
            public function findObject(string $register, string $schema, string $id): array
            {
                return [];
            }
        };

        $this->container->method('get')->willReturn($objectService);
        $this->appConfig->method('getValueString')->willReturn('decidesk');

        $result = $this->controller->publish('non-existent-uuid');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testPublishReturnsNotFoundWhenDecisionMissing()

    /**
     * Test that publish() sets isPublished=true and a valid publishedAt timestamp on success.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
     */
    public function testPublishHappyPathSetsIsPublishedAndPublishedAt(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin1')->willReturn(true);

        $decisionData  = ['id' => 'decision-uuid-1', 'outcome' => 'adopted', 'isPublished' => false];
        $objectService = new class($decisionData) {
            public function __construct(private array $decision)
            {
            }

            public function findObject(string $register, string $schema, string $id): ?array
            {
                return $this->decision;
            }

            public function saveObject(string $register, string $schema, array $object): array
            {
                return $object;
            }
        };

        $this->container->method('get')->willReturn($objectService);
        $this->appConfig->method('getValueString')->willReturn('decidesk');

        $result = $this->controller->publish('decision-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());

        $data = $result->getData();
        self::assertTrue(condition: $data['isPublished']);
        self::assertArrayHasKey(key: 'publishedAt', array: $data);
        // Verify publishedAt is a valid ISO 8601 timestamp.
        self::assertMatchesRegularExpression(
            pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            string: $data['publishedAt']
        );

    }//end testPublishHappyPathSetsIsPublishedAndPublishedAt()
}//end class
