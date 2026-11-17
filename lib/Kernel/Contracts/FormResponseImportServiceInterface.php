<?php

/**
 * Contract of FormResponseImportService
 *
 * The public surface of FormResponseImportService that other components call, so they depend
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
 * Contract of FormResponseImportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormResponseImportServiceInterface {

	/**
	 * Import the responses of every form linked to a consultation.
	 *
	 * A response with no answer text, or one longer than a reaction may be, is
	 * not imported and counted as refused.
	 *
	 * @param string $consultationId The consultation UUID.
	 *
	 * @return array{forms: int, imported: int, skipped: int, refused: int} Linked forms, new reactions,
	 *                                                                     responses imported before, refused responses.
	 *
	 * @throws \RuntimeException When the consultation is not found or the form links cannot be read.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function import(string $consultationId): array;
}//end interface
