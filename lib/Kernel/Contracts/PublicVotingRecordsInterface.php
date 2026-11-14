<?php

/**
 * Contract of PublicVotingRecords
 *
 * The public surface of PublicVotingRecords that other components call, so they depend
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
 * Contract of PublicVotingRecords.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicVotingRecordsInterface {

	/**
	 * The public members who vote, as `{id, title, subtitle?}`, sorted by name.
	 *
	 * A person the ORI API names but who holds no voting office (a chair
	 * without a vote, a griffier) is not listed: there is no record to read.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
	 * @spec openspec/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function members(): array;

	/**
	 * One member's public voting record, or null for anyone the list does not name.
	 *
	 * @param string $personId The person id
	 *
	 * @return array<string, mixed>|null `{title, subtitle?, summary, columns, rows, note}`
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
	 * @spec openspec/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function record(string $personId): ?array;
}//end interface
