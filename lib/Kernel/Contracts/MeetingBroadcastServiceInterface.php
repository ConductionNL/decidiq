<?php

/**
 * Contract of MeetingBroadcastService
 *
 * The public surface of MeetingBroadcastService that other components call, so they depend
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
 * Contract of MeetingBroadcastService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingBroadcastServiceInterface {

	/**
	 * The meeting a broadcast belongs to, or an empty string when it does not exist.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return string
	 */
	public function meetingOf(string $broadcastId): string;

	/**
	 * Pause for a closed session: the open public window closes.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @throws BroadcastRefusedException When no service is connected, the broadcast is not live, or the service refuses
	 *
	 * @return array<string, mixed> The broadcast, with its id
	 */
	public function pause(string $broadcastId): array;

	/**
	 * Record what the test showed; the broadcast is back in planned.
	 *
	 * @param string $broadcastId The broadcast
	 * @param string $result      `ok` or `problems`
	 * @param string $note        What the clerk found
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @throws BroadcastRefusedException When no service is connected, the broadcast is missing or not testing, or the result is unknown
	 *
	 * @return array<string, mixed> The broadcast, with its id
	 */
	public function recordTestResult(string $broadcastId, string $result, string $note): array;

	/**
	 * Resume after a closed session: a new public window opens.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @throws BroadcastRefusedException When no service is connected, the broadcast is not paused, or the service refuses
	 *
	 * @return array<string, mixed> The broadcast, with its id
	 */
	public function resume(string $broadcastId): array;

	/**
	 * Go live: the player address, the publication date and an open public
	 * window. Only a public meeting goes live. Live captions are asked of the
	 * service, never produced here.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @throws BroadcastRefusedException When no service is connected, the broadcast cannot start, the meeting is not public, or the service refuses
	 *
	 * @return array<string, mixed> The broadcast, with its id
	 */
	public function start(string $broadcastId): array;

	/**
	 * Start a test on the service's staff preview. The broadcast gets a
	 * preview address and no player address or publication date.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @throws BroadcastRefusedException When no service is connected, the meeting is missing, the broadcast cannot test, or the service refuses
	 *
	 * @return array<string, mixed> The broadcast, with its id
	 */
	public function startTest(string $meetingId): array;

	/**
	 * What the Broadcast widget reads: the connection and the meeting's broadcast.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return array{connected: bool, broadcast: array<string, mixed>|null}
	 */
	public function status(string $meetingId): array;

	/**
	 * Stop: the last window closes, the broadcast ends, and the recording
	 * address is kept once the service reports it.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @throws BroadcastRefusedException When no service is connected, the broadcast is not live or paused, or the service refuses
	 *
	 * @return array<string, mixed> The broadcast, with its id
	 */
	public function stop(string $broadcastId): array;
}//end interface
