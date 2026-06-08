<?php
/**
 * Decidesk Admin Settings
 *
 * Provides the admin settings form for the Decidesk application.
 *
 * @category Settings
 * @package  OCA\Decidesk\Settings
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

namespace OCA\Decidesk\Settings;

use OCA\Decidesk\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Decidesk application.
 *
 * Implements IDelegatedSettings so the form can be guarded by
 * #[AuthorizedAdminSetting(AdminSettings::class)] on the controllers that
 * mutate Decidesk configuration.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class AdminSettings implements IDelegatedSettings
{
    /**
     * Constructor.
     *
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState The initial state service.
     */
    public function __construct(
        private IAppManager $appManager,
        private IInitialState $initialState,
    ) {
    }//end __construct()

    /**
     * Get the settings form template.
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function getForm(): TemplateResponse
    {
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);
        $this->initialState->provideInitialState('version', $version);

        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            []
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function getSection(): string
    {
        return 'decidesk';
    }//end getSection()

    /**
     * Get the priority for ordering within the section.
     *
     * @return int
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()

    /**
     * Human-readable name of the delegated settings section.
     *
     * @return string|null The section name, or null to use the section default.
     */
    public function getName(): ?string
    {
        return null;
    }//end getName()

    /**
     * App config keys an authorized (delegated) admin may manage.
     *
     * Returned as a map of appId => list of allowed config keys. Decidesk
     * exposes no delegatable sub-keys yet, so this is intentionally empty;
     * the attribute still scopes the endpoint to full admins.
     *
     * @return array<string,string[]> Map of appId to allowed config keys.
     */
    public function getAuthorizedAppConfig(): array
    {
        return [];
    }//end getAuthorizedAppConfig()
}//end class
