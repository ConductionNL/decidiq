<?php

/**
 * Decidesk Settings Service
 *
 * Service for managing Decidesk application configuration and settings.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Decidesk application configuration and settings.
 */
class SettingsService
{

    /**
     * Configuration keys managed by this service.
     *
     * @var array<string>
     */
    private const CONFIG_KEYS = [
        'register',
    ];

    /**
     * Constructor for the SettingsService.
     *
     * @param IAppConfig         $appConfig    The app config interface
     * @param IAppManager        $appManager   The app manager
     * @param ContainerInterface $container    The container
     * @param IGroupManager      $groupManager The group manager
     * @param IUserSession       $userSession  The user session
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether OpenRegister is installed and available.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
     *
     * @return bool
     */
    public function isOpenRegisterAvailable(): bool
    {
        return $this->appManager->isInstalled('openregister');
    }//end isOpenRegisterAvailable()

    /**
     * Retrieve all current settings.
     *
     * Returns a flat array containing all app config values plus metadata
     * fields (openregisters, isAdmin) consumed by the frontend.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     *
     * @return array<string,mixed>
     */
    public function getSettings(): array
    {
        $settings = [];
        foreach (self::CONFIG_KEYS as $key) {
            $settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        $user    = $this->userSession->getUser();
        $isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

        return array_merge(
            $settings,
            [
                'openregisters' => $this->isOpenRegisterAvailable(),
                'isAdmin'       => $isAdmin,
            ]
        );
    }//end getSettings()

    /**
     * Update settings with the provided data.
     *
     * @param array<string,mixed> $data The data to update
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.2
     *
     * @return array<string,mixed> The updated settings
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Load configuration from decidesk_register.json via OpenRegister.
     *
     * Skips import when the configuration is already present (no force).
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     */
    public function loadConfiguration(): array
    {
        return $this->performLoadConfiguration(force: false);
    }//end loadConfiguration()

    /**
     * Force a re-import of decidesk_register.json via OpenRegister.
     *
     * Always imports regardless of whether configuration is already present.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     */
    public function forceLoadConfiguration(): array
    {
        return $this->performLoadConfiguration(force: true);
    }//end forceLoadConfiguration()

    /**
     * Internal implementation for loading/importing Decidesk configuration.
     *
     * @param bool $force When true, re-imports even if already configured.
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     */
    private function performLoadConfiguration(bool $force): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            $this->logger->warning('Decidesk: OpenRegister not available, skipping register initialization');
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        try {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            $result = $configurationService->importFromApp(appId: Application::APP_ID, force: $force);

            if (empty($result) === false) {
                $this->logger->info('Decidesk: register configuration imported successfully');
                return [
                    'success' => true,
                    'message' => 'Configuration imported successfully.',
                    'version' => ($result['version'] ?? 'unknown'),
                ];
            }

            return [
                'success' => false,
                'message' => 'Import returned an empty result.',
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: configuration import failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Configuration import failed. See server log for details.',
            ];
        }//end try
    }//end performLoadConfiguration()
}//end class
