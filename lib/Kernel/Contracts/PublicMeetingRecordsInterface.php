<?php

/**
 * Contract of PublicMeetingRecords
 *
 * The public surface of PublicMeetingRecords that other components call, so they depend
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
 * Contract of PublicMeetingRecords.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicMeetingRecordsInterface {

	/**
	 * The published meetings as `{id, title, subtitle}`, the latest first.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-req-pcr-001-a-visitor-finds-the-published-meetings-with-their-agenda
	 */
	public function meetings(): array;

	/**
	 * One published meeting with its agenda, or null when it is not published.
	 *
	 * @param string $id The payload id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-req-pcr-001-a-visitor-finds-the-published-meetings-with-their-agenda
	 */
	public function meeting(string $id): ?array;

	/**
	 * The published decisions as `{id, title, subtitle}`, the latest first.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-req-pcr-002-a-visitor-reads-the-published-decisions-with-their-outcome
	 */
	public function decisions(): array;

	/**
	 * One published decision with its outcome and vote totals, or null when it is not published.
	 *
	 * Only the totals the payload carries are shown; who voted how is on the
	 * members' voting records, under the stricter rule of the ORI votes.
	 *
	 * @param string $id The payload id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-req-pcr-002-a-visitor-reads-the-published-decisions-with-their-outcome
	 */
	public function decision(string $id): ?array;
}//end interface
