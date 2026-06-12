<?php
/**
 * Decidesk Activity Filter
 *
 * Adds a "Decidesk" filter tab to the Nextcloud Activity stream so users
 * can scope the feed to Decidesk governance events.
 *
 * @category Activity
 * @package  OCA\Decidesk\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Activity;

use OCA\Decidesk\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Activity stream filter for Decidesk governance events.
 *
 * Registered via appinfo/info.xml <activity><filters>.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class GovernanceFilter implements IFilter
{

    /**
     * Constructor.
     *
     * @param IL10N         $l10n         Translation service for the decidesk app
     * @param IURLGenerator $urlGenerator URL generator for the filter icon
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the filter identifier.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getIdentifier()
    {
        return Application::APP_ID;

    }//end getIdentifier()

    /**
     * Get the translated filter name.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getName()
    {
        return $this->l10n->t('Decidesk');

    }//end getName()

    /**
     * Get the filter priority (0-100, ascending order).
     *
     * @return int
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getPriority()
    {
        return 60;

    }//end getPriority()

    /**
     * Get the absolute URL of the filter icon.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getIcon()
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
        );

    }//end getIcon()

    /**
     * Restrict the stream to Decidesk activity types when this filter is active.
     *
     * @param string[] $types The active activity types
     *
     * @return string[]
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function filterTypes(array $types)
    {
        return array_values(
            array_intersect($types, [GovernanceSetting::TYPE_GOVERNANCE])
        );

    }//end filterTypes()

    /**
     * Only Decidesk events appear under this filter.
     *
     * @return string[]
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function allowedApps()
    {
        return [Application::APP_ID];

    }//end allowedApps()
}//end class
