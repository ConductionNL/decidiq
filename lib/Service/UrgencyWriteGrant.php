<?php

/**
 * Urgency Write Grant
 *
 * Which decisions the guarded urgency flow is saving right now. The urgency
 * fields are server-guarded: a save that changes them is refused unless the
 * urgency flow itself granted it for that decision, for the length of its own
 * save.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Holds the decisions whose urgency fields the urgency flow may write.
 *
 * Shared for the request (Nextcloud auto-wires one instance per container),
 * so the flow that grants and the listener that checks see the same set.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */
class UrgencyWriteGrant {

	/**
	 * Decision uuids currently granted, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $granted = [];

	/**
	 * Grant the urgency write for one decision.
	 *
	 * @param string $decisionId The decision uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function grant(string $decisionId): void {
		$this->granted[$decisionId] = true;
	}//end grant()

	/**
	 * End the grant for one decision.
	 *
	 * @param string $decisionId The decision uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function revoke(string $decisionId): void {
		unset($this->granted[$decisionId]);
	}//end revoke()

	/**
	 * Whether the urgency write is granted for a decision.
	 *
	 * @param string $decisionId The decision uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function isGranted(string $decisionId): bool {
		return $decisionId !== '' && isset($this->granted[$decisionId]) === true;
	}//end isGranted()
}//end class
