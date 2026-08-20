<?php

/**
 * Decidesk Member Import Service
 *
 * Resolves Nextcloud groups, group members, and email-to-account matches for
 * the governance-body member import surface (admin settings).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use InvalidArgumentException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Service backing the admin member-import endpoints.
 *
 * Participant objects themselves are created by the frontend through the
 * OpenRegister object API (per-object RBAC enforced by OpenRegister); this
 * service only resolves Nextcloud directory data (groups, group members,
 * email-to-account matches) that the import dialogs need.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class MemberImportService {

	/**
	 * Maximum number of email rows accepted per match request (server-side
	 * cap for the CSV import preview; the client mirrors the same limit).
	 *
	 * @var int
	 */
	public const MAX_MATCH_ROWS = 500;

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The Nextcloud group manager.
	 * @param IUserManager $userManager The Nextcloud user manager.
	 */
	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * List all Nextcloud groups available for member import.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @return array<int,array{id:string,displayName:string,userCount:int}>
	 */
	public function listGroups(): array {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = [
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
				'userCount' => $group->count(),
			];
		}

		return $groups;
	}//end listGroups()

	/**
	 * List the members of a Nextcloud group with the profile fields the
	 * import preview needs (display name + email).
	 *
	 * @param string $groupId The Nextcloud group id.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @return array<int,array{uid:string,displayName:string,email:string}>|null
	 *                                                                           The member rows, or null when the group does not exist.
	 */
	public function getGroupMembers(string $groupId): ?array {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return null;
		}

		$members = [];
		foreach ($group->getUsers() as $user) {
			$members[] = [
				'uid' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
				'email' => ($user->getEMailAddress() ?? ''),
			];
		}

		return $members;
	}//end getGroupMembers()

	/**
	 * Match a batch of email addresses (from a CSV import preview) to
	 * Nextcloud accounts.
	 *
	 * Each email is shape-validated; the batch is capped at MAX_MATCH_ROWS.
	 * Matching is exact (case-insensitive) on the account email. When several
	 * accounts share an email the first match wins.
	 *
	 * @param array<int,mixed> $emails The candidate email addresses.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @throws InvalidArgumentException When the batch exceeds MAX_MATCH_ROWS.
	 *
	 * @return array<string,array{uid:string,displayName:string}|null>
	 *                                                                 Map of input email => matched account (null when unmatched or
	 *                                                                 the email is malformed).
	 */
	public function matchEmails(array $emails): array {
		if (count($emails) > self::MAX_MATCH_ROWS) {
			throw new InvalidArgumentException(
				'At most ' . self::MAX_MATCH_ROWS . ' emails can be matched per request.'
			);
		}

		$matches = [];
		foreach ($emails as $email) {
			if (is_string($email) === false) {
				continue;
			}

			$normalised = strtolower(trim($email));
			if ($normalised === '' || filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
				$matches[$email] = null;
				continue;
			}

			$users = $this->userManager->getByEmail($normalised);
			if ($users === []) {
				$matches[$email] = null;
				continue;
			}

			$user = $users[0];
			$matches[$email] = [
				'uid' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}//end foreach

		return $matches;
	}//end matchEmails()
}//end class
