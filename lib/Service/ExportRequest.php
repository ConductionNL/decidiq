<?php

/**
 * Decidiq ExportRequest
 *
 * One request to export a list of decisions: the format, the list's name and
 * which of its rows.
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * What ExportBundleService::export() exports.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */
final class ExportRequest {
	/**
	 * The selected decisions; empty means "all matching the filter".
	 *
	 * @var list<mixed>
	 */
	public array $ids = [];

	/**
	 * The list's filter.
	 *
	 * @var array<string,mixed>
	 */
	public array $filter = [];

	/**
	 * The list's search text.
	 *
	 * @var string
	 */
	public string $search = '';

	/**
	 * Describe one export; which rows is set beside it.
	 *
	 * @param string $format `pdf` or `zip`.
	 * @param string $list   `Motions` or `Decisions`, the file's name.
	 */
	public function __construct(
		public readonly string $format,
		public readonly string $list,
	) {
	}//end __construct()
}//end class
