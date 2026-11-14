<?php

/**
 * Contract of MotionCoauthorService
 *
 * The public surface of MotionCoauthorService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of MotionCoauthorService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MotionCoauthorServiceInterface {

	/**
	 * Add a co-author to a motion.
	 *
	 * Only the motion proposer (owner), an existing co-author, or an admin may
	 * add co-authors (OWASP A01:2021 — Broken Access Control).
	 * Pass `$callerUid = null` to bypass the ownership check (admin/background-job paths).
	 *
	 * @param string $motionId Motion UUID
	 * @param string $personId Person UUID to add as co-author
	 * @param string|null $callerUid NC UID of the requester (null = skip access check)
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException When caller is not authorised
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.2
	 */
	public function addCoauthor(
		string $motionId,
		string $personId,
		?string $callerUid = null,
	): array;

	/**
	 * Get the version history of a motion.
	 *
	 * Only the motion proposer (owner), an existing co-author, or an admin may
	 * read the history (OWASP A01:2021 — Broken Access Control). The history
	 * carries every prior revision of the motion text plus the NC uid of each
	 * editor, so it discloses strictly more than the motion's current body —
	 * reading it is a privileged operation, exactly as changing it is.
	 * Pass `$callerUid = null` to bypass the ownership check (admin/background-job paths),
	 * which is the same contract `addCoauthor`/`removeCoauthor`/`updateMotionText` use.
	 *
	 * @param string $motionId Motion UUID
	 * @param string|null $callerUid NC UID of the requester (null = skip access check)
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \InvalidArgumentException When caller is not authorised
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.2
	 */
	public function getHistory(string $motionId, ?string $callerUid = null): array;

	/**
	 * Remove a co-author from a motion.
	 *
	 * Only the motion proposer (owner), an existing co-author, or an admin may
	 * remove co-authors (OWASP A01:2021 — Broken Access Control).
	 * Pass `$callerUid = null` to bypass the ownership check (admin/background-job paths).
	 *
	 * @param string $motionId Motion UUID
	 * @param string $personId Person UUID to remove
	 * @param string|null $callerUid NC UID of the requester (null = skip access check)
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException When caller is not authorised
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.2
	 */
	public function removeCoauthor(
		string $motionId,
		string $personId,
		?string $callerUid = null,
	): array;

	/**
	 * Update the motion text and capture a new version snapshot.
	 *
	 * Detects overlapping edits with the previous version: if any paragraph
	 * (split by double newline) is changed twice within 5 minutes by different
	 * authors, the operation throws a RuntimeException with the conflict
	 * marker so the caller can prompt for manual resolution.
	 *
	 * Only the motion proposer (owner), an existing co-author, or an admin may
	 * update the text (OWASP A01:2021 — Broken Access Control).
	 *
	 * @param string $motionId Motion UUID
	 * @param string $newText New motion text
	 * @param string $author NC UID of the author making the change (recorded in history)
	 * @param string $changeSummary Human-readable change summary
	 * @param string|null $callerUid NC UID to check for access (null = skip check for admin paths)
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException When the caller is not authorised
	 * @throws RuntimeException When an overlapping edit conflict is detected
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.2
	 */
	public function updateMotionText(
		string $motionId,
		string $newText,
		string $author,
		string $changeSummary,
		?string $callerUid = null,
	): array;
}//end interface
