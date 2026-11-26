<?php

/**
 * Contract of SigningRoundService
 *
 * The public surface of SigningRoundService that other components call, so they depend
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
 * Contract of SigningRoundService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SigningRoundServiceInterface {

	/**
	 * What can be signed, and the schema that holds it. A decision list is
	 * the meeting's list of decisions, so it is signed from the meeting.
	 *
	 * @var array<string, string>
	 */
	public const SUBJECTS = [
		'minutes' => 'minutes',
		'decision-list' => 'meeting',
		'motion' => 'decision',
	];

	/**
	 * Ask the signing service about a record that is out for signature and,
	 * once signed, store the signed copy in the record's files and link it.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId The record's UUID
	 *
	 * @return array{status: string, message: string, signedCopy?: string}
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function collect(string $subjectType, string $subjectId): array;

	/**
	 * Collect every record that is out for signature. Run by the background
	 * job, so a signed copy is stored without anyone opening the record.
	 *
	 * @return integer The number of signed copies stored.
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function collectAllSent(): int;

	/**
	 * Send a record to the signing service with its signers in order.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId The record's UUID
	 *
	 * @return array{success: bool, message: string, requestId?: ?string, signingUrl?: ?string}
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function send(string $subjectType, string $subjectId): array;
}//end interface
