<?php
/**
 * Decidesk Personal Settings Section
 *
 * Defines the Decidesk section in the Nextcloud personal settings
 * (user-settings spec).
 *
 * @category Sections
 * @package  OCA\Decidesk\Sections
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the Decidesk section in the Nextcloud personal settings.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class PersonalSection implements IIconSection
{

    /**
     * Constructor for PersonalSection.
     *
     * @param IL10N         $l            The localization service
     * @param IURLGenerator $urlGenerator The URL generator service
     *
     * @return void
     */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the section identifier.
     *
     * @return string
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getID(): string
    {
        return 'decidesk';
    }//end getID()

    /**
     * Get the display name of this section.
     *
     * @return string
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getName(): string
    {
        return $this->l->t('Decidesk');
    }//end getName()

    /**
     * Get the priority for ordering this section.
     *
     * @return int
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getPriority(): int
    {
        return 75;
    }//end getPriority()

    /**
     * Get the icon path for this section.
     *
     * @return string
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('decidesk', 'app-dark.svg');
    }//end getIcon()
}//end class
