<?php

/**
 * Unit tests for SettingsService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsService.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
 */
class SettingsServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var SettingsService
     */
    private SettingsService $service;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock IAppManager.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

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
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->service = new SettingsService(
            $this->appConfig,
            $this->appManager,
            $this->container,
            $this->groupManager,
            $this->userSession,
            $this->logger,
        );

    }//end setUp()

    /**
     * Test that isOpenRegisterAvailable returns true when installed.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testIsOpenRegisterAvailableReturnsTrue(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        self::assertTrue($this->service->isOpenRegisterAvailable());

    }//end testIsOpenRegisterAvailableReturnsTrue()

    /**
     * Test that isOpenRegisterAvailable returns false when not installed.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testIsOpenRegisterAvailableReturnsFalse(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(false);

        self::assertFalse($this->service->isOpenRegisterAvailable());

    }//end testIsOpenRegisterAvailableReturnsFalse()

    /**
     * Test that getSettings returns config values plus admin/OR metadata.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testGetSettingsReturnsConfigAndMetadata(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', '')
            ->willReturn('test-register-id');

        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $settings = $this->service->getSettings();

        self::assertSame('test-register-id', $settings['register']);
        self::assertTrue($settings['openregisters']);
        self::assertTrue($settings['isAdmin']);

    }//end testGetSettingsReturnsConfigAndMetadata()

    /**
     * Test that getSettings handles null user session gracefully.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testGetSettingsWithNullUserReturnsIsAdminFalse(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appManager->method('isInstalled')->willReturn(false);
        $this->userSession->method('getUser')->willReturn(null);

        $settings = $this->service->getSettings();

        self::assertFalse($settings['isAdmin']);
        self::assertFalse($settings['openregisters']);

    }//end testGetSettingsWithNullUserReturnsIsAdminFalse()

    /**
     * Test that updateSettings persists values and returns updated settings.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testUpdateSettingsPersistsAndReturns(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'register', 'new-value');

        $this->appConfig->method('getValueString')
            ->willReturn('new-value');

        $this->appManager->method('isInstalled')->willReturn(true);
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->updateSettings(['register' => 'new-value']);

        self::assertSame('new-value', $result['register']);

    }//end testUpdateSettingsPersistsAndReturns()

    /**
     * Test that loadConfiguration returns failure when OpenRegister not available.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testLoadConfigurationFailsWithoutOpenRegister(): void
    {
        $this->appManager->method('isInstalled')->willReturn(false);

        $result = $this->service->loadConfiguration();

        self::assertFalse($result['success']);
        self::assertStringContainsString('not installed', $result['message']);

    }//end testLoadConfigurationFailsWithoutOpenRegister()
}//end class
