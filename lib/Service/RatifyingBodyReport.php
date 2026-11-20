<?php

/**
 * Ratifying Body Report
 *
 * The day an urgent decision was first reported to the body that ratifies
 * it. Whichever step comes first sets it (the report action, or the
 * ratification agenda item reaching a meeting); a later step leaves it as it
 * is. The write is one of the guarded urgency fields, so it runs under the
 * urgency write grant.
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
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Sets an urgent decision's report date once.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */
class RatifyingBodyReport {

	/**
	 * The decision field that holds the report date.
	 *
	 * @var string
	 */
	public const FIELD = 'reportedToRatifyingBodyAt';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $timeFactory The report day.
	 * @param UrgencyWriteGrant $grant       Lets the save past the urgency field guard.
	 */
	public function __construct(
		private readonly ITimeFactory $timeFactory,
		private readonly UrgencyWriteGrant $grant,
	) {
	}//end __construct()

	/**
	 * Whether the decision is urgent and not yet reported.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function isDue(array $decision): bool {
		return ($decision['isUrgent'] ?? false) === true && trim((string)($decision[self::FIELD] ?? '')) === '';
	}//end isDue()

	/**
	 * The decision as it is saved with today's report date, without its metadata.
	 *
	 * @param array<string, mixed> $decision The decision; the caller checked isDue().
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function stamped(array $decision): array {
		unset($decision['@self']);
		$decision[self::FIELD] = $this->timeFactory->getDateTime()->format('Y-m-d');
		return $decision;
	}//end stamped()

	/**
	 * Run a save of the decision with the urgency write grant held, and drop it after.
	 *
	 * @param string   $decisionId The decision.
	 * @param callable $save       The save; its answer is passed back.
	 *
	 * @return mixed What the save answered.
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function granted(string $decisionId, callable $save): mixed {
		$this->grant->grant(decisionId: $decisionId);
		try {
			return $save();
		} finally {
			$this->grant->revoke(decisionId: $decisionId);
		}
	}//end granted()
}//end class
