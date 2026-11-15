<?php

/**
 * Decidiq Consultation Close Run
 *
 * The state of one sweep of the consultation auto-close job.
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

/**
 * One auto-close sweep: the services it closes with, the clock it compares
 * deadlines against, and how many consultations it closed or failed to close.
 *
 * Built by ConsultationAutoCloseJob::run and never leaves that class.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
final class ConsultationCloseRun {
	/**
	 * Consultations closed so far.
	 *
	 * @var int
	 */
	public int $closed = 0;

	/**
	 * Consultations that failed to close so far.
	 *
	 * @var int
	 */
	public int $errors = 0;

	/**
	 * Start the sweep.
	 *
	 * @param object $objectService    The OpenRegister ObjectService.
	 * @param object $lifecycleService The participation lifecycle service.
	 * @param int    $now              Current unix timestamp.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly object $lifecycleService,
		public readonly int $now,
	) {
	}//end __construct()
}//end class
