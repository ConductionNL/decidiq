<?php

/**
 * Contract of ConnectionReportService
 *
 * The public surface of ConnectionReportService that other components call, so they depend
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

/**
 * Contract of ConnectionReportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionReportServiceInterface {

	/**
	 * Report the bound signing service and translation adapter to integriq.
	 *
	 * Never throws. Without integriq nothing is resolved, sent or logged,
	 * because a missing optional app is not a fault.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	public function reportBindings(): void;

	/**
	 * Ask integriq to resolve every connection whose settings the save touched.
	 *
	 * A save that names none of a connection's keys leaves that connection
	 * alone. Integriq reads the saved values itself and decides the status
	 * (design D6).
	 *
	 * @param array<string, mixed> $saved The payload the settings save carried.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-001-decidiq-declares-its-outside-connections-in-one-static-file
	 */
	public function refreshFromSave(array $saved): void;
}//end interface
