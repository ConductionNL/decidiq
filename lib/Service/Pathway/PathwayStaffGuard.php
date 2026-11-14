<?php

/**
 * Who may run the access and delivery steps of a member onboarding or offboarding.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Pathway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Pathway;

use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Granting and revoking a member's groups and delivering files into their
 * account are secretariat work. A user may do it for a body when they are a
 * Nextcloud administrator, hold the secretariat role (its own group or a
 * group an administrator mapped to it), or are the secretary of that body on
 * its roster. Everyone else is refused, and so is anyone when the body is
 * unknown or the roster cannot be read.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
class PathwayStaffGuard {

	/**
	 * The secretariat role's own group.
	 *
	 * @var string
	 */
	public const SECRETARIAT_GROUP = 'decidiq-secretariat';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager            $groupManager The Nextcloud group manager.
	 * @param SettingsServiceInterface $settings     The app settings (role to group mapping).
	 * @param RegisterObjectStore      $store        The register's objects.
	 * @param LoggerInterface          $logger       The logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly SettingsServiceInterface $settings,
		private readonly RegisterObjectStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the user may run the pathway steps of a body.
	 *
	 * @param string $userId The Nextcloud uid.
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function mayManage(string $userId, string $bodyId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		if ($bodyId === '') {
			return false;
		}

		foreach ($this->secretariatGroups() as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return $this->isBodySecretary(userId: $userId, bodyId: $bodyId);
	}//end mayManage()

	/**
	 * The secretariat role's own group plus the groups mapped to it.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function secretariatGroups(): array {
		$mapped = ($this->settings->roleGroupMapping()['secretariat'] ?? []);
		return array_values(array_unique(array_merge([self::SECRETARIAT_GROUP], $mapped)));
	}//end secretariatGroups()

	/**
	 * Whether the user is a secretary on the body's roster. Fails closed when
	 * the roster cannot be read.
	 *
	 * @param string $userId The Nextcloud uid.
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function isBodySecretary(string $userId, string $bodyId): bool {
		try {
			$rows = $this->store->findAll(
				schema: 'participant',
				filters: ['governanceBody' => $bodyId, 'role' => 'secretary', 'nextcloudUserId' => $userId]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: could not read the roster to authorise a member pathway step', ['exception' => $e]);
			return false;
		}

		foreach ($rows as $row) {
			if (($row['nextcloudUserId'] ?? null) === $userId
				&& ($row['role'] ?? null) === 'secretary'
				&& PathwaySteps::relationId(value: ($row['governanceBody'] ?? null)) === $bodyId
			) {
				return true;
			}
		}

		return false;
	}//end isBodySecretary()

}//end class
