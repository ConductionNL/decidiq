<?php

/**
 * Decidiq Publication Record Change
 *
 * A withdraw or rectify request on a publication record as
 * PublicationController hands it to PublicationService: the record, the
 * acting staff member and the stated reason.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

/**
 * A staff member's withdraw or rectify request on a publication record.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
final class PublicationRecordChange {

	/**
	 * Constructor.
	 *
	 * @param string $recordId The publication record.
	 * @param string $userId   The acting staff member.
	 * @param string $reason   The stated reason.
	 */
	public function __construct(
		public readonly string $recordId,
		public readonly string $userId,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
