<?php
/**
 * Decidesk Settings Controller
 *
 * Controller for managing Decidesk application settings.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\PublicationConfigService;
use OCA\Decidesk\Service\SettingsService;
use OCA\Decidesk\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
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
     * @param IRequest                 $request           The request object
     * @param SettingsService          $settingsService   The settings service
     * @param IUserSession             $userSession       The user session
     * @param PublicationConfigService $publicationConfig The publication configuration service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private \OCA\Decidesk\Service\PublicationConfigService $publicationConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

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
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            $this->settingsService->getSettings()
        );
    }//end index()

    /**
     * Update settings with provided data.
     *
     * Requires admin privileges — enforced via the AuthorizedAdminSetting
     * attribute (NC28+ settings panel).
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function create(): JSONResponse
    {
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
     * Requires admin privileges — enforced via the AuthorizedAdminSetting
     * attribute (NC28+ settings panel).
     *
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
     * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.2
     * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.4
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function load(): JSONResponse
    {
        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse($result);
    }//end load()

    /**
     * Read the per-governance-body publication configuration.
     *
     * Returned to authenticated staff so the publish/withdraw UI can resolve
     * each body's target catalog and policy. Read-only; safe for any authed user.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function getPublicationConfig(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(['config' => $this->publicationConfig->getAll()]);
    }//end getPublicationConfig()

    /**
     * Persist the per-governance-body publication configuration.
     *
     * Admin-only via the AuthorizedAdminSetting attribute. Body: { config: { <bodyId>: { catalog, policy, attendance } } }.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function setPublicationConfig(): JSONResponse
    {
        $config = $this->request->getParam('config', []);
        if (is_array($config) === false) {
            $config = [];
        }

        $saved = $this->publicationConfig->save($config);

        return new JSONResponse(['success' => true, 'config' => $saved]);
    }//end setPublicationConfig()
}//end class
