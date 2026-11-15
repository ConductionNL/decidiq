<?php

/**
 * Contract of PublicationService
 *
 * The public surface of PublicationService that other components call, so they depend
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
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of PublicationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationServiceInterface {

	/**
	 * Publish an eligible governance object.
	 *
	 * Runs the deny-list + eligibility gates, builds the allow-list payload,
	 * persists it as an immutable PublicationPayload, sets `publicationDate` so
	 * the public-group RBAC rule makes it anonymously readable, routes into the
	 * configured OpenCatalogi catalog, writes the PublicationRecord, and appends
	 * a publish audit entry.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId UUID of the source object.
	 * @param string $actorId Nextcloud UID of the publishing staff member.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed> Keys: `record` (the PublicationRecord) and `warnings` (string[]).
	 */
	public function publish(string $sourceType, string $sourceId, string $actorId): array;

	/**
	 * Rectify a publication: publish a corrected new version and withdraw the
	 * old one in the same operation. Published payloads are never edited in place.
	 *
	 * @param string $recordId UUID of the PublicationRecord to rectify.
	 * @param string $actorId Nextcloud UID of the rectifying staff member.
	 * @param string $reason Reason recorded against the withdrawn prior version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws MissingObjectException When the prior record does not exist.
	 *
	 * @return array<string,mixed> Keys: `record` (new), `previous` (withdrawn) and `warnings` (string[]).
	 */
	public function rectify(string $recordId, string $actorId, string $reason): array;

	/**
	 * Withdraw a publication with a mandatory reason.
	 *
	 * Sets `depublicationDate` on the payload (removing it from the public-group
	 * RBAC surface), retracts the OpenCatalogi publication (surfacing + marking
	 * pending on failure — never a silent success), resets the source object's
	 * published state, records actor/reason/timestamp on the record and in the
	 * audit trail, and soft-retains the payload.
	 *
	 * @param string $recordId UUID of the PublicationRecord.
	 * @param string $actorId Nextcloud UID of the withdrawing staff member.
	 * @param string $reason Mandatory withdraw reason.
	 * @param array<int,mixed> $keepPapers Papers a rectified version still publishes.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @throws InvalidArgumentException When the reason is empty.
	 * @throws MissingObjectException When the record does not exist.
	 *
	 * @return array<string,mixed> Keys: `record` (the PublicationRecord) and `warnings` (string[]).
	 */
	public function withdraw(string $recordId, string $actorId, string $reason, array $keepPapers=[]): array;
}//end interface
