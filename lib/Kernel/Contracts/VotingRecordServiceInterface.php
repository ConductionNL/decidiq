<?php

/**
 * Contract of VotingRecordService
 *
 * The public surface of VotingRecordService that other components call, so they depend
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

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of VotingRecordService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VotingRecordServiceInterface {

	/**
	 * A person's votes in closed rounds that were not secret, newest first.
	 *
	 * @param string $personId The person id
	 *
	 * @return list<array<string, mixed>> Rows of vote, date, decision, choice, result, party and body
	 *
	 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function forPerson(string $personId): array;

	/**
	 * Refuse a person the caller may not read: OpenRegister answers with the
	 * caller's own rights, so a person they cannot see is refused here before
	 * any vote is read, the same way as one that does not exist.
	 *
	 * @param string $personId The person id
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the caller cannot read the person
	 *
	 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function requireReadablePerson(string $personId): void;
}//end interface
