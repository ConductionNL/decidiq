<?php

/**
 * Decidiq PlannedAgendaReportException
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
 * @spec openspec/changes/archive/2026-11-24-planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A refused long-term agenda report, with the HTTP status the refusal
 * deserves and a sentence the clerk can act on.
 *
 * @spec openspec/changes/archive/2026-11-24-planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string         $message  What was refused.
	 * @param int            $status   The HTTP status.
	 * @param Throwable|null $previous The cause.
	 *
	 * @spec openspec/changes/archive/2026-11-24-planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 */
	public function __construct(string $message, int $status=422, ?Throwable $previous=null) {
		// The status is the exception code: getStatus() reads it back.
		parent::__construct(message: $message, code: $status, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @spec openspec/changes/archive/2026-11-24-planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @return int
	 */
	public function getStatus(): int {
		return (int) $this->getCode();
	}//end getStatus()
}//end class
