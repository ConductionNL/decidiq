<?php

/**
 * Decidiq Notification Preference Clearable Field
 *
 * One optional notification-preference override that an empty string clears
 * back to the Nextcloud account default: its request parameter, the check a
 * non-empty value must pass and the message returned when it does not.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Closure;

/**
 * A clearable preference override and its validation rule.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
final class ClearableField {

	/**
	 * Constructor.
	 *
	 * @param string                $param    Request parameter / change key
	 * @param Closure(string): bool $isValid  Predicate applied to a non-empty string value
	 * @param string                $errorMsg Message returned when the predicate fails
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function __construct(
		public readonly string $param,
		public readonly Closure $isValid,
		public readonly string $errorMsg,
	) {
	}//end __construct()

	/**
	 * Whether a raw, non-empty request value is an accepted override.
	 *
	 * @param mixed $raw Raw request value
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function accepts(mixed $raw): bool {
		return is_string($raw) === true && ($this->isValid)($raw) === true;
	}//end accepts()
}//end class
