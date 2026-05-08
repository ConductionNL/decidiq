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
use OCP\Settings\ISettings;

/**
 * Provides the admin settings form for the Decidesk application.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class AdminSettings implements ISettings
{
    /**
     * Constructor.
     *
     * @param IAppManager $appManager The app manager.
     */
    public function __construct(
        private IAppManager $appManager,
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

        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            ['version' => $version]
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
}//end class
