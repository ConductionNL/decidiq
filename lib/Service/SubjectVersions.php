<?php

/**
 * Subject Versions
 *
 * An object as it was and as it now is, with its uuid: what one update did to
 * the subject of an approval route (REQ-DWP-003).
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The two versions of an updated subject.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 */
final class SubjectVersions {
	/**
	 * Constructor.
	 *
	 * @param string               $subject The subject's uuid.
	 * @param array<string, mixed> $before  The subject as it was.
	 * @param array<string, mixed> $after   The subject as it now is.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly array $before,
		public readonly array $after,
	) {
	}//end __construct()
}//end class
