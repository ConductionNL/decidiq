<?php

/**
 * Decidiq Participation Staff Guard
 *
 * Answers the two identity questions the citizen-participation endpoints ask:
 * "who is acting?" and "does the actor hold staff (governance-body) authority?".
 *
 * The staff determination is membership of the configured `chair_group`, with a
 * fallback to Nextcloud admin when no group is configured. Extracted from
 * ParticipationController so the session / group / app-config collaborators live
 * with the policy they implement rather than with HTTP response building.
 *
 * Fails CLOSED: an unauthenticated session is never staff.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Resolves the acting user and their staff (governance-body) authority for the
 * citizen-participation endpoints. Fail-closed.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationStaffGuard {
	/**
	 * Constructor for ParticipationStaffGuard.
	 *
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager
	 * @param IAppConfig $appConfig App config (staff group)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Resolve the acting user's UID.
	 *
	 * @return string|null The UID, or null when no user is signed in.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end currentUid()

	/**
	 * Decide whether the acting user holds staff (governance-body) authority.
	 *
	 * Nextcloud admins always qualify; otherwise membership of the configured
	 * chair group is required. No signed-in user is never staff.
	 *
	 * @return bool True when the actor may perform staff actions.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function isStaff(): bool {
		$uid = $this->currentUid();
		if ($uid === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		$chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');
		if ($chairGroup === '') {
			return false;
		}

		return $this->groupManager->isInGroup($uid, $chairGroup);
	}//end isStaff()
}//end class
