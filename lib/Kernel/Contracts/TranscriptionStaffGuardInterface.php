<?php

/**
 * Contract of TranscriptionStaffGuard
 *
 * The public surface of TranscriptionStaffGuard that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of TranscriptionStaffGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TranscriptionStaffGuardInterface {

	/**
	 * The UID of the session user, or an empty string when unauthenticated.
	 *
	 * @return string The Nextcloud UID.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function currentUserId(): string;

	/**
	 * Staff guard for a governance body id.
	 *
	 * Non-admins must hold a chair/secretary role on at least one meeting of the
	 * body. Fails CLOSED: when no such meeting can be resolved, access is denied.
	 *
	 * @param string $bodyId Governance body UUID.
	 *
	 * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function forBody(string $bodyId): ?JSONResponse;

	/**
	 * Staff guard for a meeting id (chair/secretary or NC admin).
	 *
	 * @param string $meetingId Meeting UUID.
	 *
	 * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function forMeeting(string $meetingId): ?JSONResponse;

	/**
	 * Staff guard for a transcript id — resolves its meeting then delegates.
	 *
	 * Fails CLOSED for non-admins: an unresolvable transcript/meeting yields 403.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function forTranscript(string $transcriptId): ?JSONResponse;

	/**
	 * Listener guard for a transcript's recording: any participant of the
	 * transcript's meeting may play it, besides the chair, secretary and admins.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function forTranscriptListener(string $transcriptId): ?JSONResponse;
}//end interface
