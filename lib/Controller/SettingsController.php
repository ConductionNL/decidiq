<?php

/**
 * Decidesk Settings Controller
 *
 * Controller for managing Decidesk application settings.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
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

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing Decidesk application settings.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.4
 */
class SettingsController extends Controller
{
    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest        $request         The request object
     * @param SettingsService $settingsService The settings service
     * @param IGroupManager   $groupManager    The group manager for admin checks
     * @param IUserSession    $userSession     The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Check whether the current user is an admin. Returns a 403 JSONResponse if not.
     *
     * @return JSONResponse|null Null if admin, 403 response if not.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Admin required'], Http::STATUS_FORBIDDEN);
        }

        return null;
    }//end requireAdmin()

    /**
     * Retrieve all current settings.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.4
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        return new JSONResponse(
            $this->settingsService->getSettings()
        );
    }//end index()

    /**
     * Update settings with provided data.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }

        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $config,
            ]
        );
    }//end create()

    /**
     * Re-import the configuration from decidesk_register.json.
     *
     * Forces a fresh import regardless of version, auto-configuring
     * all schema and register IDs from the import result.
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.2
     * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.4
     *
     * @return JSONResponse
     */
    public function load(): JSONResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }

        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse($result);
    }//end load()
}//end class
