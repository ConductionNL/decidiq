<?php

/**
 * Contract of TranscriptionService
 *
 * The public surface of TranscriptionService that other components call, so they depend
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

use DomainException;
use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of TranscriptionService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TranscriptionServiceInterface {

	/**
	 * Re-run agenda alignment for a Transcript (pure re-runnable derivation).
	 *
	 * Joins each segment's time window against the meeting-conduct timeline
	 * (agenda item start + actualDuration). Segments inside an item's window get
	 * its UUID; out-of-window segments are left unassigned (never guessed). When
	 * the meeting has no conduct timeline the transcript stays flat (all
	 * segments unassigned). Re-running never re-transcribes.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The re-aligned Transcript object.
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function align(string $transcriptId): array;

	/**
	 * Attach a transcription source to a meeting and record consent.
	 *
	 * Creates (or updates) a Transcript object in status `pending` with the
	 * source file reference and the consent record. Consent is a server-side
	 * precondition for transcription (enforced in {@see self::submit()}); here
	 * it is captured at attach time.
	 *
	 * @param string $meetingId Meeting UUID.
	 * @param string $sourceType Source type (`talk-recording`|`uploaded-file`).
	 * @param string $sourcePath Path of the source file in the meeting folder.
	 * @param string $confirmedBy Nextcloud UID confirming consent (server-attributed).
	 * @param string $language Optional BCP-47 language hint.
	 *
	 * @return array<string,mixed> The created Transcript object.
	 *
	 * @throws MissingObjectException When the meeting cannot be found.
	 * @throws InvalidArgumentException When source type/path is invalid.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function attach(
		string $meetingId,
		string $sourceType,
		string $sourcePath,
		string $confirmedBy,
		string $language = '',
	): array;

	/**
	 * Whether a SpeechToText provider is available on this instance.
	 *
	 * Provider absence is a first-class unavailable state — callers use this to
	 * keep the attach/consent flow working while hiding/disabling transcribe.
	 *
	 * @return bool True when at least one SpeechToText provider is registered.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isProviderAvailable(): bool;

	/**
	 * List the candidate transcription sources for a meeting.
	 *
	 * @param string $meetingId Meeting UUID.
	 *
	 * @return array<int,array<string,string>> Candidate sources.
	 *
	 * @throws MissingObjectException When the meeting cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function listSources(string $meetingId): array;

	/**
	 * Execute the transcription for a Transcript (called from the background job).
	 *
	 * Moves status pending → processing, runs the SpeechToText provider over the
	 * source file, parses neutral-label segments, writes the transcript text
	 * file into the meeting folder, runs agenda alignment, and moves status to
	 * `done`. On any provider error the status is set to `failed` with the
	 * reason stored (a first-class state, re-requestable).
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The updated Transcript object.
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function process(string $transcriptId): array;

	/**
	 * Set the transcript/recording retention policy for a governance body.
	 *
	 * @param string $bodyId The governance body UUID.
	 * @param string $policy One of keep|delete-recording|delete-both.
	 * @param int $days Retention window in days.
	 *
	 * @return void
	 *
	 * @throws MissingObjectException When the governance body cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function setRetentionPolicy(string $bodyId, string $policy, int $days): void;

	/**
	 * Submit a Transcript for transcription (consent precondition enforced).
	 *
	 * Refuses without a recorded consent confirmation — a server-side check,
	 * not a UI-only gate. When no SpeechToText provider is available the
	 * Transcript is left untouched and a domain exception signals unavailable.
	 *
	 * The actual transcription runs asynchronously in {@see TranscriptionJob};
	 * this method validates preconditions and is the seam the controller calls.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The Transcript object (status unchanged at submit).
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 * @throws DomainException When consent is missing (code 422) or no provider (code 503).
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function submit(string $transcriptId): array;
}//end interface
