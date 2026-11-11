<?php

/**
 * Governance body provenance.
 *
 * The producing app and its own reference: the key a governance-body command
 * resolves on, so a re-run finds the body it raised before.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;


/**
 * Which app raised a governance body, under which of its own references.
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
final class BodyProvenance {
	/**
	 * Describe one provenance pair.
	 *
	 * @param string $sourceApp         App id of the producer.
	 * @param string $externalReference The producer's own id for the originating record.
	 */
	public function __construct(
		public readonly string $sourceApp,
		public readonly string $externalReference,
	) {
	}//end __construct()

	/**
	 * Whether a stored body row carries exactly this pair.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return bool True when both halves match.
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function isProvenanceOf(array $row): bool {
		$matchesApp = ((string)($row['sourceApp'] ?? '') === $this->sourceApp);
		$matchesRef = ((string)($row['externalReference'] ?? '') === $this->externalReference);

		return $matchesApp === true && $matchesRef === true;
	}//end isProvenanceOf()
}//end class
