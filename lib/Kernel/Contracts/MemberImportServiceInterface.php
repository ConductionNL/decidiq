<?php

/**
 * Contract of MemberImportService
 *
 * The public surface of MemberImportService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of MemberImportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MemberImportServiceInterface {

	/**
	 * List the members of a Nextcloud group with the profile fields the
	 * import preview needs (display name + email).
	 *
	 * @param string $groupId The Nextcloud group id.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @return array<int,array{uid:string,displayName:string,email:string}>|null
	 *                                                                           The member rows, or null when the group does not exist.
	 */
	public function getGroupMembers(string $groupId): ?array;

	/**
	 * List all Nextcloud groups available for member import.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @return array<int,array{id:string,displayName:string,userCount:int}>
	 */
	public function listGroups(): array;

	/**
	 * Match a batch of email addresses (from a CSV import preview) to
	 * Nextcloud accounts.
	 *
	 * Each email is shape-validated; the batch is capped at MAX_MATCH_ROWS.
	 * Matching is exact (case-insensitive) on the account email. When several
	 * accounts share an email the first match wins.
	 *
	 * @param array<int,mixed> $emails The candidate email addresses.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @throws InvalidArgumentException When the batch exceeds MAX_MATCH_ROWS.
	 *
	 * @return array<string,array{uid:string,displayName:string}|null>
	 *                                                                 Map of input email => matched account (null when unmatched or
	 *                                                                 the email is malformed).
	 */
	public function matchEmails(array $emails): array;
}//end interface
