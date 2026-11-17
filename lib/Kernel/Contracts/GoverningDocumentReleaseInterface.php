<?php

/**
 * Contract of GoverningDocumentRelease
 *
 * The public surface of GoverningDocumentRelease that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of GoverningDocumentRelease.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GoverningDocumentReleaseInterface {

	/**
	 * Release every approved document whose planned date has arrived.
	 *
	 * Each release sets `effectiveDate` to the planned date, through
	 * OpenRegister, so the document's own audit trail records it. A document
	 * that cannot be saved is logged and left for the next run.
	 *
	 * @param DateTimeImmutable|null $now The moment of the run; the clock when null.
	 *
	 * @return array{released: list<string>, overdue: list<string>, failed: list<string>} The document ids.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	public function sweep(?DateTimeImmutable $now=null): array;
}//end interface
