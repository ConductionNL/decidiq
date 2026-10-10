<?php

/**
 * Decidiq Paper Summary Refused Exception
 *
 * A paper summary request that may not go ahead, with the HTTP status the
 * controller answers: 401 without a user, 403 outside the secretariat or
 * outside a confidentiality restriction's circle, 422 for a paper that is not
 * attached to the item, 503 without a TaskProcessing provider.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A refused paper summary request and its HTTP status.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 */
class PaperSummaryRefusedException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the clerk
	 * @param int $status The HTTP status to answer
	 *
	 * @return void
	 */
	public function __construct(string $message, private readonly int $status=403) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
