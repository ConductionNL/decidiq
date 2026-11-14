<?php

/**
 * Decidiq Urgency Policy Validator
 *
 * Finds what is wrong with a process template's `urgencyPolicy`, the per-body
 * configuration of the urgent decision procedure.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/process-configuration/spec.md#requirement-per-template-urgency-policy
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Validates an `urgencyPolicy` block. Every fault is an error: a policy that
 * cannot be honoured is refused at save rather than read loosely later, the
 * same fail-closed posture as the transition-graph validation.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/process-configuration/spec.md#requirement-per-template-urgency-policy
 */
final class UrgencyPolicyValidator {

	/**
	 * The role tokens that may declare a decision urgent: the meeting and
	 * membership roles of the register, plus `board-member`.
	 *
	 * @var string[]
	 */
	public const ROLE_TOKENS = ['chair', 'vice-chair', 'secretary', 'treasurer', 'member', 'board-member'];

	/**
	 * The faults of a policy, empty when it can be honoured.
	 *
	 * @param mixed    $policy     The `urgencyPolicy` value as written.
	 * @param callable $bodyExists Answers whether a governance body reference resolves: fn (string $ref): bool.
	 *
	 * @return string[] Human-readable errors.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/process-configuration/spec.md#requirement-per-template-urgency-policy
	 */
	public function errors(mixed $policy, callable $bodyExists): array {
		if (is_array($policy) === false || ($policy !== [] && array_is_list($policy) === true)) {
			return ['urgencyPolicy must be an object.'];
		}

		return array_merge(
			$this->roleErrors(roles: ($policy['allowedTriggerRoles'] ?? null)),
			$this->floorErrors(floor: ($policy['minimumNoticeFloorHours'] ?? null)),
			$this->boundErrors(bounds: ($policy['responseDeadlineHours'] ?? null)),
			$this->ratificationErrors(policy: $policy, bodyExists: $bodyExists)
		);
	}//end errors()

	/**
	 * A non-empty list of recognised role tokens.
	 *
	 * @param mixed $roles The `allowedTriggerRoles` value.
	 *
	 * @return string[]
	 */
	private function roleErrors(mixed $roles): array {
		if (is_array($roles) === false || $roles === []) {
			return ['urgencyPolicy.allowedTriggerRoles must name at least one role.'];
		}

		$errors = [];
		foreach ($roles as $role) {
			if (is_string($role) === false || in_array($role, self::ROLE_TOKENS, true) === false) {
				$named = gettype($role);
				if (is_scalar($role) === true) {
					$named = (string)$role;
				}

				$errors[] = 'urgencyPolicy.allowedTriggerRoles names "' . $named . '", which is not a recognised role ('
					. implode(', ', self::ROLE_TOKENS) . ').';
			}
		}

		return $errors;
	}//end roleErrors()

	/**
	 * An absent floor, or a whole number of hours of at least 0.
	 *
	 * @param mixed $floor The `minimumNoticeFloorHours` value.
	 *
	 * @return string[]
	 */
	private function floorErrors(mixed $floor): array {
		if ($floor === null || (is_int($floor) === true && $floor >= 0)) {
			return [];
		}

		return ['urgencyPolicy.minimumNoticeFloorHours must be a whole number of hours, 0 or more.'];
	}//end floorErrors()

	/**
	 * Absent bounds, or `{min, max}` in whole hours with min not above max.
	 *
	 * @param mixed $bounds The `responseDeadlineHours` value.
	 *
	 * @return string[]
	 */
	private function boundErrors(mixed $bounds): array {
		if ($bounds === null) {
			return [];
		}

		$min = null;
		$max = null;
		if (is_array($bounds) === true) {
			$min = ($bounds['min'] ?? null);
			$max = ($bounds['max'] ?? null);
		}

		if (is_int($min) === false || is_int($max) === false || $min < 0 || $max < 0) {
			return ['urgencyPolicy.responseDeadlineHours must be {min, max} in whole hours, 0 or more.'];
		}

		if ($min > $max) {
			return ['urgencyPolicy.responseDeadlineHours is inverted: min ' . $min . ' is above max ' . $max . '.'];
		}

		return [];
	}//end boundErrors()

	/**
	 * A ratifying body that resolves whenever ratification is required, which it is by default.
	 *
	 * @param array<string, mixed> $policy     The policy.
	 * @param callable             $bodyExists Answers whether a body reference resolves.
	 *
	 * @return string[]
	 */
	private function ratificationErrors(array $policy, callable $bodyExists): array {
		$required = ($policy['ratificationRequired'] ?? true);
		if (is_bool($required) === false) {
			return ['urgencyPolicy.ratificationRequired must be true or false.'];
		}

		if ($required === false) {
			return [];
		}

		$body = ($policy['ratifyingBody'] ?? null);
		if (is_string($body) === false || trim($body) === '') {
			return ['urgencyPolicy.ratifyingBody is required when ratificationRequired is true.'];
		}

		if ($bodyExists($body) !== true) {
			return ['urgencyPolicy.ratifyingBody "' . $body . '" is not a governance body.'];
		}

		return [];
	}//end ratificationErrors()
}//end class
