<?php

/**
 * Decidiq FullExportRun
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
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use ZipArchive;

/**
 * One full export being written: the open archive and the manifest that
 * describes it, filled schema by schema. Built by FullExportService::build
 * and never handed out.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
final class FullExportRun {

	/**
	 * The manifest: the app, when, every exported schema, what was skipped,
	 * how many files and which could not be read.
	 *
	 * @var array<string, mixed>
	 */
	public array $manifest;

	/**
	 * Start an export.
	 *
	 * @param ZipArchive $zip        The open archive.
	 * @param string     $exportedAt When the export was started, as an ISO-8601 instant.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ZipArchive $zip,
		string $exportedAt,
	) {
		$this->manifest = [
			'app' => 'decidiq',
			'exportedAt' => $exportedAt,
			'schemas' => [],
			'skipped' => [],
			'files' => 0,
			'missingFiles' => [],
		];
	}//end __construct()
}//end class
