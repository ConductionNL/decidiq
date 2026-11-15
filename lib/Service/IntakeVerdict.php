<?php

/**
 * Intake Verdict
 *
 * One admissibility verdict as an intake step yields it: the verdict, the
 * ground it rests on, who gave it and when.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The verdict an intake step records, before it is checked.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */
final class IntakeVerdict {
	/**
	 * Build the verdict.
	 *
	 * @param string $verdict   One of AdmissibilityVerdictService::VERDICTS.
	 * @param string $ground    The ground it rests on.
	 * @param string $decidedBy Who gave it.
	 * @param string $decidedAt When, as an ISO-8601 instant; now when empty.
	 */
	public function __construct(
		public readonly string $verdict,
		public readonly string $ground = '',
		public readonly string $decidedBy = '',
		public readonly string $decidedAt = '',
	) {
	}//end __construct()
}//end class
