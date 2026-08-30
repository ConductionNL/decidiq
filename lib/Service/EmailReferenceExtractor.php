<?php

/**
 * Decidiq Email Reference Extractor
 *
 * Thin, stateless text-processing helper that extracts decision/motion
 * identifiers (e.g. "B-2026-031", "Motie-2025-01") from an email subject
 * or body. It exists ONLY to feed an auto-suggest ("link this email to
 * dossier X") to the ADR-019 Email integration leaf — it never stores a
 * link itself.
 *
 * Per ADR-022 (migrate-email-links-to-email-leaf, decision D3): the
 * retired in-app EmailLink store is replaced by the registry email-object
 * link. The leaf owns the linking; this helper only proposes a candidate
 * target when the leaf does not already offer a suggestion of its own.
 * Per ADR-031, domain-specific text/NLP processing legitimately stays in
 * PHP — this is not a lifecycle/aggregation/calculation/notification that
 * an x-openregister-* extension could express.
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
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-2.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Stateless decision-reference extraction helper that feeds the email
 * leaf's link-suggestion — never a link store.
 *
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-2.2
 */
class EmailReferenceExtractor {
	/**
	 * Extract decision/motion references from an email subject or body.
	 *
	 * Matches patterns like 'B-2026-031', 'Besluit-2024-001',
	 * 'Decision-2024-001', 'Motie-2025-01', or 'Amendement 2025 03'. The
	 * returned identifiers are offered to the email integration leaf as
	 * link-suggestion candidates; this method performs no persistence.
	 *
	 * @param string $text Email subject or body.
	 *
	 * @return array<int, string> Distinct extracted decision/motion identifiers.
	 *
	 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-2.2
	 */
	public function extract(string $text): array {
		if ($text === '') {
			return [];
		}

		$rawMatches = [];
		preg_match_all(
			'/\b(?:Decision|Besluit|B|Motie|M|A|Amendement)[-_ ](\d{4})[-_ ](\d{2,4})\b/i',
			$text,
			$rawMatches
		);

		if (count($rawMatches[0]) === 0) {
			return [];
		}

		return array_values(array_unique($rawMatches[0]));
	}//end extract()
}//end class
