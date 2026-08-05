<?php
/**
 * Decidesk Activity Setting
 *
 * Declares the `decidesk_governance` activity type in the user's
 * Activity notification settings.
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Activity settings entry for Decidesk governance events.
 *
 * Registered via appinfo/info.xml <activity><settings> (NC32 Activity API —
 * IRegistrationContext has no activity registration method).
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class GovernanceSetting extends ActivitySettings
{

    /**
     * Activity type identifier shared by all Decidesk governance events.
     *
     * @var string
     */
    public const TYPE_GOVERNANCE = 'decidesk_governance';

    /**
     * Constructor.
     *
     * @param IL10N $l10n Translation service for the decidesk app
     */
    public function __construct(
        private readonly IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Get the activity type identifier.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getIdentifier()
    {
        return self::TYPE_GOVERNANCE;

    }//end getIdentifier()

    /**
     * Get the translated setting name.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getName()
    {
        return $this->l10n->t('A governance event (decision, meeting, vote or resolution) happened in Decidesk');

    }//end getName()

    /**
     * Get the settings group identifier.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getGroupIdentifier()
    {
        return 'other';

    }//end getGroupIdentifier()

    /**
     * Get the translated settings group name.
     *
     * @return string
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function getGroupName()
    {
        return $this->l10n->t('Other activities');

    }//end getGroupName()

    /**
     * Get the setting priority (0-100, ascending order).
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
     * Whether notifications for this type are enabled by default.
     *
     * @return bool
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function isDefaultEnabledNotification()
    {
        return true;

    }//end isDefaultEnabledNotification()
}//end class
