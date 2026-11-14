<?php

/**
 * Contract of OriImportService
 *
 * The public surface of OriImportService that other components call, so they depend
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

use OCA\Decidiq\Service\Ori\OriImportReport;

/**
 * Contract of OriImportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OriImportServiceInterface {

	/**
	 * Import the ORI register into decidiq's own objects.
	 *
	 * @param string $sourceRegister The ORI register slug.
	 * @param string $cutoverDate    The end date given to the memberships of an inactive member (Y-m-d).
	 *
	 * @return OriImportReport
	 */
	public function import(string $sourceRegister='ori', string $cutoverDate=''): OriImportReport;

	/**
	 * What an import would do, writing nothing.
	 *
	 * @param string $sourceRegister The ORI register slug.
	 * @param string $cutoverDate    The end date given to the memberships of an inactive member (Y-m-d).
	 *
	 * @return OriImportReport
	 */
	public function preview(string $sourceRegister='ori', string $cutoverDate=''): OriImportReport;

	/**
	 * Undo the last import.
	 *
	 * @return OriImportReport
	 */
	public function rollback(): OriImportReport;

	/**
	 * What a rollback would do, writing nothing.
	 *
	 * @return OriImportReport
	 */
	public function previewRollback(): OriImportReport;
}//end interface
