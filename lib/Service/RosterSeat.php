<?php

/**
 * Roster seat.
 *
 * One seat a governance-body command fills: the body, the person on it and
 * the role they hold.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;


/**
 * A person's seat on a body, in one role.
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
final class RosterSeat {
	/**
	 * The membership role.
	 *
	 * @var string
	 */
	public string $role = 'member';

	/**
	 * Describe one seat; the role is set beside it.
	 *
	 * @param string $bodyId   The body's id.
	 * @param string $personId The person's id.
	 */
	public function __construct(
		public readonly string $bodyId,
		public readonly string $personId,
	) {
	}//end __construct()
}//end class
