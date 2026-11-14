<?php

/**
 * Contract for the meeting pack (board book) compilation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

/**
 * Compiles a meeting pack and reports its freshness.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BoardBookServiceInterface {

	/**
	 * Compile the next version of a meeting's pack and deliver it.
	 *
	 * @param string $meetingId  UUID of the meeting
	 * @param string $userId     Who compiles it
	 * @param string $changeNote Why this version differs; required from version 2
	 *
	 * @return array<string, mixed> `success`, `code`, `message`, and for a pack its version, path, pageCount, skipped and deliveryFailures
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function compile(string $meetingId, string $userId, string $changeNote=''): array;

	/**
	 * Whether a compilation request may go ahead, before it is queued.
	 *
	 * @param string $meetingId  UUID of the meeting
	 * @param string $changeNote The change note sent with the request
	 *
	 * @return array{ok: bool, code: string, message: string, nextVersion: int}
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
	 */
	public function precheck(string $meetingId, string $changeNote): array;

	/**
	 * The latest pack's version, fingerprint and reports, and whether the agenda changed since.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return array<string, mixed>|null Null when the meeting cannot be read
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function freshness(string $meetingId): ?array;
}//end interface
