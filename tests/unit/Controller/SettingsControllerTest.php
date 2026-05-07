<?php

/**
 * Unit tests for SettingsController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\SettingsController;
use OCA\Decidesk\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsController.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.4
 */
class SettingsControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var SettingsController
     */
    private SettingsController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

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
     * Mock admin IUser.
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $adminUser;

    /**
     * Mock non-admin IUser.
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $nonAdminUser;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(originalClassName: IRequest::class);
        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->groupManager    = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession     = $this->createMock(originalClassName: IUserSession::class);

        $this->adminUser = $this->createMock(originalClassName: IUser::class);
        $this->adminUser->method('getUID')->willReturn('admin');

        $this->nonAdminUser = $this->createMock(originalClassName: IUser::class);
        $this->nonAdminUser->method('getUID')->willReturn('regularuser');

        $this->controller = new SettingsController(
            request: $this->request,
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );

    }//end setUp()

    /**
     * Test that index() returns a JSONResponse containing the settings from the service.
     *
     * @return void
     */
    public function testIndexReturnsJsonResponseWithSettings(): void
    {
        $settings = [
            'register'      => 'some-uuid',
            'openregisters' => true,
            'isAdmin'       => false,
        ];

        $this->userSession->method('getUser')->willReturn($this->nonAdminUser);

        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willReturn($settings);

        $result = $this->controller->index();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: $settings, actual: $result->getData());

    }//end testIndexReturnsJsonResponseWithSettings()

    /**
     * Test that create() calls updateSettings with request params and returns success for admin.
     *
     * @return void
     */
    public function testCreateCallsUpdateSettingsAndReturnsSuccess(): void
    {
        $params  = ['register' => 'new-uuid'];
        $updated = ['register' => 'new-uuid', 'openregisters' => true, 'isAdmin' => false];

        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($this->adminUser);

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($params);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($params)
            ->willReturn($updated);

        $result = $this->controller->create();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertTrue(condition: $result->getData()['success']);
        self::assertArrayHasKey(key: 'config', array: $result->getData());

    }//end testCreateCallsUpdateSettingsAndReturnsSuccess()

    /**
     * Test that create() returns HTTP 403 for non-admin users.
     *
     * @return void
     */
    public function testCreateReturnsForbiddenForNonAdmin(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($this->nonAdminUser);

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('regularuser')
            ->willReturn(false);

        $this->settingsService->expects($this->never())
            ->method('updateSettings');

        $result = $this->controller->create();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());

    }//end testCreateReturnsForbiddenForNonAdmin()

    /**
     * Test that create() returns HTTP 403 when no user is logged in.
     *
     * @return void
     */
    public function testCreateReturnsForbiddenForUnauthenticatedUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->settingsService->expects($this->never())
            ->method('updateSettings');

        $result = $this->controller->create();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());

    }//end testCreateReturnsForbiddenForUnauthenticatedUser()

    /**
     * Test that load() returns the result of loadConfiguration for admin.
     *
     * @return void
     */
    public function testLoadReturnsConfigurationResult(): void
    {
        $loadResult = [
            'success' => true,
            'message' => 'Configuration imported successfully.',
            'version' => '0.1.0',
        ];

        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($this->adminUser);

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->with(force: true)
            ->willReturn($loadResult);

        $result = $this->controller->load();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertTrue(condition: $result->getData()['success']);

    }//end testLoadReturnsConfigurationResult()

    /**
     * Test that load() returns HTTP 403 for non-admin users.
     *
     * @return void
     */
    public function testLoadReturnsForbiddenForNonAdmin(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($this->nonAdminUser);

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('regularuser')
            ->willReturn(false);

        $this->settingsService->expects($this->never())
            ->method('loadConfiguration');

        $result = $this->controller->load();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());

    }//end testLoadReturnsForbiddenForNonAdmin()

    /**
     * Test that load() returns HTTP 403 when no user is logged in.
     *
     * @return void
     */
    public function testLoadReturnsForbiddenForUnauthenticatedUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->settingsService->expects($this->never())
            ->method('loadConfiguration');

        $result = $this->controller->load();

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());

    }//end testLoadReturnsForbiddenForUnauthenticatedUser()

}//end class
