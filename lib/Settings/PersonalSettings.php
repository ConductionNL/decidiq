<?php
/**
 * Decidesk Personal Settings
 *
 * Provides the personal (per-user) settings form for the Decidesk
 * application: notification preferences, display preferences, absence
 * delegation, and communication preferences (user-settings spec).
 *
 * @category Settings
 * @package  OCA\Decidesk\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/user-settings/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Settings;

use OCA\Decidesk\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Personal settings panel (OCP\Settings\ISettings) for Decidesk.
 *
 * Renders the Vue mount point; all data flows through the per-user REST
 * endpoints (/api/notification-preference and /api/preferences/{key}), which
 * scope reads/writes to the session user.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class PersonalSettings implements ISettings
{
    /**
     * Get the personal settings form template.
     *
     * @return TemplateResponse
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(
            Application::APP_ID,
            'settings/personal',
            []
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     *
     * @spec openspec/specs/user-settings/spec.md
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
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()
}//end class
