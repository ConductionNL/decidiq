<?php

/**
 * Which Nextcloud groups a member pathway may assign or revoke.
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
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Pathway;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\OnboardingProvisioningService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\IAppConfig;

/**
 * The groups come from the body's role-to-groups mapping; an administrator
 * lists the groups member provisioning may touch at all, and the protected
 * groups are never allowed. Only reads.
 *
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
final class MemberGroupPolicy {

	/**
	 * The refusal naming the groups an administrator did not allow.
	 */
	private const NOT_ALLOWED = 'These groups are not allowed for member provisioning: %s.'
		. ' An administrator lists the allowed groups in the Decidiq admin settings.';


	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store     The register's objects.
	 * @param IAppConfig|null     $appConfig The app config holding the allowed groups.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ?IAppConfig $appConfig=null,
	) {
	}//end __construct()

	/**
	 * The refusal for mapped groups an administrator did not allow, or null when all are allowed.
	 *
	 * @param list<string> $names The group ids.
	 * @param string       $what  What would have happened to them: assigned or revoked.
	 *
	 * @return PathwayResult|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function refuseNotAllowed(array $names, string $what): ?PathwayResult {
		$notAllowed = $this->notAllowed(names: $names);
		if ($notAllowed === []) {
			return null;
		}

		return new PathwayResult(
			outcome: PathwayResult::REFUSED,
			message: sprintf(self::NOT_ALLOWED . ' No group was ' . $what . '.', implode(', ', $notAllowed))
		);
	}//end refuseNotAllowed()

	/**
	 * The body's role to groups mapping, cleaned.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return array<string, list<string>>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function mappedGroups(string $bodyId): array {
		if ($bodyId === '') {
			return [];
		}

		$body    = $this->store->find(schema: 'governance-body', uuid: $bodyId);
		$mapping = ($body['memberGroups'] ?? []);
		if (is_array($mapping) === false) {
			return [];
		}

		$clean = [];
		foreach ($mapping as $role => $groups) {
			if (is_string($role) === false || is_array($groups) === false) {
				continue;
			}

			$names = $this->groupNames(groups: $groups);
			if ($names !== []) {
				$clean[$role] = $names;
			}
		}

		return $clean;
	}//end mappedGroups()

	/**
	 * The non-empty group names of one role's mapping, trimmed and unique.
	 *
	 * @param array<mixed> $groups The mapped values.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function groupNames(array $groups): array {
		$names = [];
		foreach ($groups as $group) {
			if (is_string($group) === true && trim($group) !== '') {
				$names[] = trim($group);
			}
		}

		return array_values(array_unique($names));
	}//end groupNames()

	/**
	 * Every group the body maps, for any role.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	public function allMappedGroups(string $bodyId): array {
		$all = [];
		foreach ($this->mappedGroups(bodyId: $bodyId) as $groups) {
			$all = array_merge($all, $groups);
		}

		return array_values(array_unique($all));
	}//end allMappedGroups()

	/**
	 * The groups an administrator allows a body to map, without the protected ones.
	 *
	 * @return list<string> The group ids; empty without a setting or app config.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function allowedGroups(): array {
		if ($this->appConfig === null) {
			return [];
		}

		$raw     = $this->appConfig->getValueString(AppIdentity::APP_ID, OnboardingProvisioningService::ALLOWED_GROUPS_KEY, '');
		$allowed = [];
		foreach (explode(',', $raw) as $name) {
			$name = trim($name);
			if ($name === ''
				|| in_array(strtolower($name), OnboardingProvisioningService::PROTECTED_GROUPS, true) === true
				|| in_array($name, $allowed, true) === true
			) {
				continue;
			}

			$allowed[] = $name;
		}

		return $allowed;
	}//end allowedGroups()

	/**
	 * The mapped groups an administrator did not allow, or that are protected.
	 *
	 * @param list<string> $names The group ids the body maps.
	 *
	 * @return list<string> The refused group ids, in the order given.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function notAllowed(array $names): array {
		$allowed = $this->allowedGroups();
		$refused = [];
		foreach ($names as $name) {
			if (in_array($name, $allowed, true) === false && in_array($name, $refused, true) === false) {
				$refused[] = $name;
			}
		}

		return $refused;
	}//end notAllowed()

}//end class
