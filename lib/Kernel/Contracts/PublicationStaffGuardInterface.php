<?php

/**
 * Contract of PublicationStaffGuard
 *
 * The public surface of PublicationStaffGuard that other components call, so they depend
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
 * Contract of PublicationStaffGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationStaffGuardInterface {

	/**
	 * Outcome: the caller holds the required authority.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Outcome: the PublicationRecord the check was keyed by does not exist.
	 */
	public const RECORD_NOT_FOUND = 'record-not-found';

	/**
	 * Per-object staff check keyed by an existing PublicationRecord.
	 *
	 * @param string $recordId UUID of the PublicationRecord
	 *
	 * @return string One of self::ALLOWED, self::FORBIDDEN or self::RECORD_NOT_FOUND
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function checkRecord(string $recordId): string;

	/**
	 * Per-object staff check keyed by a publication source object.
	 *
	 * Admin passes. Otherwise the caller must hold a chair/secretary role on the
	 * meeting linked to the source object (decision/agenda/minutes).
	 *
	 * @param string $sourceType One of decision|agenda|minutes
	 * @param string $sourceId UUID of the source object
	 *
	 * @return string One of self::ALLOWED or self::FORBIDDEN
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function checkSource(string $sourceType, string $sourceId): string;

	/**
	 * Resolve the acting user's UID.
	 *
	 * The publication actions raise their own 401 before asking either check
	 * below, which is why those may treat the session user as present.
	 *
	 * @return string|null The UID, or null when no user is signed in
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function currentUid(): ?string;
}//end interface
