<?php

/**
 * Contract of BroadcastCaptionService
 *
 * The public surface of BroadcastCaptionService that other components call, so they depend
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

use OCA\Decidiq\Exception\BroadcastRefusedException;

/**
 * Contract of BroadcastCaptionService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BroadcastCaptionServiceInterface {

	/**
	 * Make the caption file of a broadcast from the meeting's aligned transcript.
	 *
	 * @param string $broadcastId The broadcast
	 * @param string $language    The track's language code
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @throws BroadcastRefusedException 404 unknown broadcast, 409 no finished transcript, 422 not aligned or a bad language, 500 not written
	 *
	 * @return array{language: string, filePath: string, cues: int}
	 */
	public function derive(string $broadcastId, string $language): array;

	/**
	 * Release a reviewed caption track: a read-only link share, recorded on the broadcast.
	 *
	 * @param string $broadcastId The broadcast
	 * @param string $language    The track's language code
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 *
	 * @throws BroadcastRefusedException 404 unknown broadcast, 409 not ended or no file yet, 422 meeting not public
	 *
	 * @return array<string, mixed> The stored broadcast, with its id and whether the service attached the track
	 */
	public function release(string $broadcastId, string $language): array;
}//end interface
