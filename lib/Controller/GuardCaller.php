<?php

/**
 * Decidiq Guard Caller
 *
 * Who an authorization guard is asked about: nobody for an administrator
 * (they bypass the guard) or for no session, the signed-in user otherwise.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The uid an authorization guard checks.
 *
 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
 */
final class GuardCaller {
	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The user session
	 * @param IGroupManager $groupManager Tells who is an administrator
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The caller's uid for the guard: null when nobody is signed in or the
	 * caller is a Nextcloud administrator (admin bypass), the uid otherwise.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
	 */
	public function uid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		return $uid;
	}//end uid()
}//end class
