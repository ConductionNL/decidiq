<?php

/**
 * Decidiq Notification Preference Delegation Request
 *
 * The three raw delegation fields of a notification-preference update
 * (delegate, delegationFrom, delegationUntil), read once from the request
 * by NotificationPreferenceRequestValidator.
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

/**
 * The raw delegation fields of a preference update request.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
final class DelegationRequest {

	/**
	 * Constructor.
	 *
	 * @param mixed $delegate Raw delegate request value
	 * @param mixed $from     Raw delegationFrom request value
	 * @param mixed $until    Raw delegationUntil request value
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function __construct(
		public readonly mixed $delegate,
		public readonly mixed $from,
		public readonly mixed $until,
	) {
	}//end __construct()

	/**
	 * Whether the request carries none of the delegation fields.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function isAbsent(): bool {
		return $this->delegate === null && $this->from === null && $this->until === null;
	}//end isAbsent()

	/**
	 * Whether the request clears the delegation (an empty-string delegate).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function clears(): bool {
		return $this->delegate !== null && (string)$this->delegate === '';
	}//end clears()
}//end class
