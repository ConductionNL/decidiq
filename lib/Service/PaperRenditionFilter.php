<?php

/**
 * Decidiq Paper Rendition Filter
 *
 * Picks the PDF over the Office paper it was made of, so a meeting package
 * carries the PDF (agenda-office-files-to-pdf, REQ-OPDF-003).
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
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Leaves out an Office paper whose PDF sits among the same files.
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
final class PaperRenditionFilter {

	/**
	 * Leave out an Office paper whose PDF sits among the item's files, so the
	 * package carries the PDF and not the Word file.
	 *
	 * @param array<int, object>       $nodes      The item's file nodes.
	 * @param array<int|string, mixed> $renditions The item's `paperRenditions`.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
	 *
	 * @return array<int, object>
	 */
	public function preferPdf(array $nodes, array $renditions): array {
		$present = [];
		foreach ($nodes as $node) {
			if (method_exists($node, 'getId') === true) {
				$present[(int)$node->getId()] = true;
			}
		}

		$replaced = [];
		foreach ($renditions as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$pdfId = (int)($entry['pdfFileId'] ?? 0);
			if ($pdfId > 0 && isset($present[$pdfId]) === true) {
				$replaced[(int)$entry['sourceFileId']] = true;
			}
		}

		return array_values(
			array_filter(
				$nodes,
				static fn (object $node): bool => method_exists($node, 'getId') === false
					|| isset($replaced[(int)$node->getId()]) === false
			)
		);
	}//end preferPdf()
}//end class
