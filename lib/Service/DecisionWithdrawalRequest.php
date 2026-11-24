<?php

/**
 * Decidiq Decision Withdrawal Request
 *
 * One request to withdraw a decision: which decision, who withdraws it
 * (actor kind), why, and the Nextcloud user asking.
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
 * @spec openspec/specs/decision-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * An immutable withdrawal request, passed through the withdraw flow.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
final class DecisionWithdrawalRequest {

	/**
	 * Build the request.
	 *
	 * @param string      $decisionId    UUID of the decision to withdraw
	 * @param string      $withdrawnBy   Actor kind: bestuursorgaan|belanghebbende
	 * @param string      $reason        Why, written for the person who receives it
	 * @param string|null $currentUserId Nextcloud UID of the requesting user
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function __construct(
		public readonly string $decisionId,
		public readonly string $withdrawnBy,
		public readonly string $reason,
		public readonly ?string $currentUserId,
	) {
	}//end __construct()
}//end class
