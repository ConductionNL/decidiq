<?php

/**
 * Contract of GoverningDocumentConsolidationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use DateTimeImmutable;
use OCA\Decidiq\Exception\GoverningDocumentVersionRefusedException;

/**
 * Contract of GoverningDocumentConsolidationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GoverningDocumentConsolidationServiceInterface {

	/**
	 * The governing document, read with the caller's own rights.
	 *
	 * @param string $documentId The document uuid.
	 *
	 * @return array<string, mixed>|null The document, or null when the caller cannot read it or it does not exist.
	 */
	public function readableDocument(string $documentId): ?array;

	/**
	 * The version of a document in force on a date, read with the caller's own rights.
	 *
	 * @param string            $documentId The document uuid.
	 * @param DateTimeImmutable $date       The date.
	 *
	 * @return array<string, mixed>|null The version, or null when none is in force.
	 */
	public function inForceOn(string $documentId, DateTimeImmutable $date): ?array;

	/**
	 * Refuse a version the register may not hold: an amendment without its
	 * enacting decision, a duplicate version number, or a seal out of order.
	 *
	 * @param array<string, mixed> $version The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 */
	public function assertAcceptable(array $version): void;
}//end interface
