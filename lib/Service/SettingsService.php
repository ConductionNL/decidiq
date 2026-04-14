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
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
 */
class SettingsService
{

    /**
     * Configuration keys managed by this service.
     *
     * Includes the main register slug plus schema slugs for Minutes, Decision,
     * and ActionItem so the frontend initializeStores() can register object stores.
     *
     * @var array<string>
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-3
     */
    private const CONFIG_KEYS = [
        'register',
        'minutesSchema',
        'decisionSchema',
        'actionItemSchema',
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
     * The Minutes, Decision, and ActionItem schema/register slugs are registered
     * directly in src/store/store.js::OBJECT_TYPES (alongside all other entity types)
     * and do not require additional settings keys — the frontend resolves them from the
     * static OBJECT_TYPES map after confirming OpenRegister is available.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-3.1
     *
     * @return array<string,mixed>
     */
    public function getSettings(): array
    {
        // Default schema slugs match the slugs defined in decidesk_register.json.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-3.
        $defaults = [
            'minutesSchema'    => 'minutes',
            'decisionSchema'   => 'decision',
            'actionItemSchema' => 'action-item',
        ];

        $settings = [];
        foreach (self::CONFIG_KEYS as $key) {
            $value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            if ($value !== '') {
                $settings[$key] = $value;
            } else {
                $settings[$key] = ($defaults[$key] ?? '');
            }
        }

        $user    = $this->userSession->getUser();
        $isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

        return array_merge(
            $settings,
            [
                'openregisters' => $this->isOpenRegisterAvailable(),
                // UI-HINT ONLY: isAdmin is used exclusively to control frontend rendering
                // (e.g. showing/hiding admin-only settings panels). It MUST NOT be used
                // for server-side access control decisions. All admin-gated backend routes
                // enforce the admin check independently via IGroupManager::isAdmin().
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
     * @param bool $force Force re-import even if already configured.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     */
    public function loadConfiguration(bool $force=false): array
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
    }//end loadConfiguration()
}//end class
