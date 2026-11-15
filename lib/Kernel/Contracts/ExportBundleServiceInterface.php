<?php

/**
 * Contract of ExportBundleService
 *
 * The public surface of ExportBundleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Service\ExportRequest;

/**
 * Contract of ExportBundleService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportBundleServiceInterface {

	/**
	 * Build an export.
	 *
	 * @param ExportRequest $request The format, the list's name and which of its rows.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @throws ExportBundleException When the export is refused; the status says why.
	 *
	 * @return array{status:string,format:string,name:string,path:string,fileId:int|null}
	 */
	public function export(ExportRequest $request): array;

	/**
	 * Whether one PDF can be made here: filinq's merge service is installed.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @return bool
	 */
	public function pdfAvailable(): bool;
}//end interface
