<?php

/**
 * Decidiq Meeting Broadcast Service
 *
 * Runs a meeting's public broadcast: a staff-only test, going live, pausing
 * for a closed session, resuming and stopping. The streaming service sits
 * behind integriq ({@see StreamingClient}); this service holds the order of
 * the calls and the public windows.
 *
 * Broadcast writes are service-owned and happen in system context after the
 * controller checked that the caller is the meeting's chair or secretary
 * (TranscriptionStaffGuard). Every lifecycle move written here is one the
 * schema declares (lib/Settings/register.d/120-meeting-broadcast.json), and a
 * move it does not declare is refused before the streaming service is asked.
 *
 * Public windows are seconds from Meeting.openedAt, the origin
 * TranscriptAlignmentService uses, so a transcript segment and a window
 * compare directly. `recordingStart` is the second in the service's recording
 * where a window begins: what the service reports, else the sum of the
 * earlier windows (a service that cuts the paused time).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Test, start, pause, resume and stop a meeting's broadcast.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
 */
class MeetingBroadcastService {
	/**
	 * The broadcast schema.
	 */
	private const SCHEMA = 'meeting-broadcast';

	/**
	 * The results a clerk may record for a test.
	 */
	private const TEST_RESULTS = ['ok', 'problems'];

	/**
	 * The public-window arithmetic.
	 *
	 * @var BroadcastWindows
	 */
	private readonly BroadcastWindows $windows;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param StreamingClient        $streaming     The streaming service, through integriq
	 * @param ITimeFactory           $time          The clock
	 * @param IUserSession           $userSession   Who records a test result
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly StreamingClient $streaming,
		private readonly ITimeFactory $time,
		private readonly IUserSession $userSession,
	) {
		$this->windows = new BroadcastWindows();
	}//end __construct()

	/**
	 * Whether a streaming service is connected.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return bool
	 */
	public function isConnected(): bool {
		return $this->streaming->isConnected();
	}//end isConnected()

	/**
	 * What the Broadcast widget reads: the connection and the meeting's broadcast.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return array{connected: bool, broadcast: array<string, mixed>|null}
	 */
	public function status(string $meetingId): array {
		return ['connected' => $this->isConnected(), 'broadcast' => $this->broadcastOfMeeting(meetingId: $meetingId)];
	}//end status()

	/**
	 * The meeting a broadcast belongs to, or an empty string when it does not exist.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return string
	 */
	public function meetingOf(string $broadcastId): string {
		return (string)($this->read(schema: self::SCHEMA, id: $broadcastId)['meeting'] ?? '');
	}//end meetingOf()

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
	public function startTest(string $meetingId): array {
		$this->streaming->requireConnected();
		$meeting = $this->read(schema: 'meeting', id: $meetingId);
		if ($meeting === null) {
			throw new BroadcastRefusedException(message: 'Meeting not found', status: 404);
		}

		$broadcast = $this->broadcastOfMeeting(meetingId: $meetingId);
		if ($broadcast !== null) {
			$this->requireState(broadcast: $broadcast, allowed: ['planned', 'testing'], verb: 'tested');
		}

		$answer  = $this->streaming->call(operation: 'start-test', body: ['meeting' => $meetingId, 'title' => (string)($meeting['title'] ?? '')]);
		$preview = $this->requireUrl(answer: $answer, key: 'previewUrl', what: 'preview address');

		if ($broadcast === null) {
			$broadcast = $this->write(broadcast: $this->newBroadcast(meetingId: $meetingId, meeting: $meeting));
		}

		$broadcast['previewUrl'] = $preview;
		$broadcast['lifecycle']  = 'testing';
		return $this->write(broadcast: $broadcast);
	}//end startTest()

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
	public function recordTestResult(string $broadcastId, string $result, string $note): array {
		$this->streaming->requireConnected();
		$broadcast = $this->load(broadcastId: $broadcastId);
		if (in_array($result, self::TEST_RESULTS, true) === false) {
			throw new BroadcastRefusedException(message: 'A test result is ok or problems', status: 422);
		}

		if (($broadcast['lifecycle'] ?? '') !== 'testing') {
			throw new BroadcastRefusedException(message: 'No test is running for this broadcast', status: 409);
		}

		$broadcast['testResult'] = $result;
		$broadcast['testNote']   = $note;
		$broadcast['testedBy']   = (string)$this->userSession->getUser()?->getDisplayName();
		$broadcast['testedAt']   = $this->time->getDateTime()->format(DATE_ATOM);
		$broadcast['lifecycle']  = 'planned';
		return $this->write(broadcast: $broadcast);
	}//end recordTestResult()

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
	public function start(string $broadcastId): array {
		$this->streaming->requireConnected();
		$broadcast = $this->load(broadcastId: $broadcastId);
		$this->requireState(broadcast: $broadcast, allowed: ['planned', 'testing'], verb: 'started');

		$meetingId = (string)($broadcast['meeting'] ?? '');
		$meeting   = ($this->read(schema: 'meeting', id: $meetingId) ?? []);
		if (($meeting['isPublic'] ?? false) !== true) {
			throw new BroadcastRefusedException(message: 'Only a public meeting can be broadcast', status: 422);
		}

		$answer = $this->streaming->call(operation: 'start', body: ['meeting' => $meetingId, 'title' => (string)($meeting['title'] ?? '')]);
		$broadcast['playerUrl'] = $this->requireUrl(answer: $answer, key: 'playerUrl', what: 'player address');
		if (isset($broadcast['publicationDate']) === false) {
			$broadcast['publicationDate'] = $this->time->getDateTime()->format(DATE_ATOM);
		}

		$second                     = $this->offset(meeting: $meeting);
		$broadcast['publicWindows'] = $this->windows->open(broadcast: $broadcast, second: $second, answer: $answer);
		$broadcast['liveCaptions']  = $this->requestLiveCaptions(meetingId: $meetingId);
		$broadcast['lifecycle']     = 'live';
		return $this->write(broadcast: $broadcast);
	}//end start()

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
	public function pause(string $broadcastId): array {
		$this->streaming->requireConnected();
		$broadcast = $this->load(broadcastId: $broadcastId);
		$this->requireState(broadcast: $broadcast, allowed: ['live'], verb: 'paused');

		$meetingId = (string)($broadcast['meeting'] ?? '');
		$this->streaming->call(operation: 'pause', body: ['meeting' => $meetingId]);

		$second                     = $this->offset(meeting: ($this->read(schema: 'meeting', id: $meetingId) ?? []));
		$broadcast['publicWindows'] = $this->windows->close(broadcast: $broadcast, second: $second);
		$broadcast['lifecycle']     = 'paused';
		return $this->write(broadcast: $broadcast);
	}//end pause()

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
	public function resume(string $broadcastId): array {
		$this->streaming->requireConnected();
		$broadcast = $this->load(broadcastId: $broadcastId);
		$this->requireState(broadcast: $broadcast, allowed: ['paused'], verb: 'resumed');

		$meetingId = (string)($broadcast['meeting'] ?? '');
		$answer    = $this->streaming->call(operation: 'resume', body: ['meeting' => $meetingId]);

		$second                     = $this->offset(meeting: ($this->read(schema: 'meeting', id: $meetingId) ?? []));
		$broadcast['publicWindows'] = $this->windows->open(broadcast: $broadcast, second: $second, answer: $answer);
		$broadcast['lifecycle']     = 'live';
		return $this->write(broadcast: $broadcast);
	}//end resume()

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
	public function stop(string $broadcastId): array {
		$this->streaming->requireConnected();
		$broadcast = $this->load(broadcastId: $broadcastId);
		$this->requireState(broadcast: $broadcast, allowed: ['live', 'paused'], verb: 'stopped');

		$meetingId = (string)($broadcast['meeting'] ?? '');
		$answer    = $this->streaming->call(operation: 'stop', body: ['meeting' => $meetingId]);

		$second                     = $this->offset(meeting: ($this->read(schema: 'meeting', id: $meetingId) ?? []));
		$broadcast['publicWindows'] = $this->windows->close(broadcast: $broadcast, second: $second);
		$recording                  = (string)($answer['recordingUrl'] ?? '');
		if ($recording !== '') {
			$broadcast['recordingUrl'] = $recording;
		}

		$broadcast['lifecycle'] = 'ended';
		return $this->write(broadcast: $broadcast);
	}//end stop()

	/**
	 * Ask the service for live captions: `requested` when it accepts,
	 * `unavailable` when it says no or cannot answer.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-005-live-captions-come-from-the-streaming-service
	 *
	 * @return string
	 */
	private function requestLiveCaptions(string $meetingId): string {
		try {
			$answer = $this->streaming->call(operation: 'live-captions', body: ['meeting' => $meetingId]);
		} catch (BroadcastRefusedException $e) {
			return 'unavailable';
		}

		if (($answer['accepted'] ?? false) === true) {
			return 'requested';
		}

		return 'unavailable';
	}//end requestLiveCaptions()

	/**
	 * Seconds from the meeting's opening (else its scheduled start) to now, never negative.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return int
	 */
	private function offset(array $meeting): int {
		$origin = strtotime((string)($meeting['openedAt'] ?? $meeting['scheduledDate'] ?? ''));
		if ($origin === false) {
			return 0;
		}

		return max(0, ($this->time->getTime() - $origin));
	}//end offset()

	/**
	 * Refuse a move the lifecycle does not declare from the current state.
	 *
	 * @param array<string, mixed> $broadcast The broadcast
	 * @param list<string>         $allowed   The states the action may start from
	 * @param string               $verb      What the action does, for the message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @throws BroadcastRefusedException When the state does not allow the action
	 *
	 * @return void
	 */
	private function requireState(array $broadcast, array $allowed, string $verb): void {
		$state = (string)($broadcast['lifecycle'] ?? 'planned');
		if ($state === 'ended') {
			throw new BroadcastRefusedException(message: 'The broadcast has ended', status: 409);
		}

		if (in_array($state, $allowed, true) === false) {
			throw new BroadcastRefusedException(message: 'The broadcast is ' . $state . ', so it cannot be ' . $verb, status: 409);
		}
	}//end requireState()

	/**
	 * A URL the service had to send, or a 502 naming what was missing.
	 *
	 * @param array<string, mixed> $answer The service's answer
	 * @param string               $key    The key
	 * @param string               $what   What it is, for the message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @throws BroadcastRefusedException When the answer holds no address
	 *
	 * @return string
	 */
	private function requireUrl(array $answer, string $key, string $what): string {
		$url = (string)($answer[$key] ?? '');
		if ($url === '') {
			throw new BroadcastRefusedException(message: 'The streaming service sent no ' . $what, status: 502);
		}

		return $url;
	}//end requireUrl()

	/**
	 * A new broadcast for a meeting, with what the public row shows copied in:
	 * the title, the body's name, the start and, for a session, its evening's title.
	 *
	 * @param string               $meetingId The meeting
	 * @param array<string, mixed> $meeting   The meeting's data
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-004-each-sessions-broadcast-names-its-evening-for-residents
	 *
	 * @return array<string, mixed>
	 */
	private function newBroadcast(string $meetingId, array $meeting): array {
		$broadcast = ['meeting' => $meetingId, 'title' => (string)($meeting['title'] ?? $meetingId), 'lifecycle' => 'planned'];
		$body      = $this->read(schema: 'governance-body', id: (string)($meeting['governanceBody'] ?? ''));
		if (is_string($body['name'] ?? null) === true && $body['name'] !== '') {
			$broadcast['bodyName'] = $body['name'];
		}

		if (is_string($meeting['scheduledDate'] ?? null) === true && $meeting['scheduledDate'] !== '') {
			$broadcast['scheduledDate'] = $meeting['scheduledDate'];
		}

		// A session names its evening, so residents find the evening's sessions together.
		$evening = $this->read(schema: 'meeting', id: (string)($meeting['parentMeeting'] ?? ''));
		if (is_string($evening['title'] ?? null) === true && $evening['title'] !== '') {
			$broadcast['eveningTitle'] = $evening['title'];
		}

		return $broadcast;
	}//end newBroadcast()

	/**
	 * The meeting's one broadcast, or null.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return array<string, mixed>|null
	 */
	private function broadcastOfMeeting(string $meetingId): ?array {
		if ($meetingId === '') {
			return null;
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => self::SCHEMA, 'meeting' => $meetingId], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		if ($rows === []) {
			return null;
		}

		$stored = $rows[0]->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$rows[0]->getUuid()] + $stored;
	}//end broadcastOfMeeting()

	/**
	 * A broadcast, or a 404.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @throws BroadcastRefusedException When it does not exist
	 *
	 * @return array<string, mixed>
	 */
	private function load(string $broadcastId): array {
		$broadcast = $this->read(schema: self::SCHEMA, id: $broadcastId);
		if ($broadcast === null) {
			throw new BroadcastRefusedException(message: 'Broadcast not found', status: 404);
		}

		return ['id' => $broadcastId] + $broadcast;
	}//end load()

	/**
	 * Read one object in system context.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		unset($data['id'], $data['@self']);
		return $data;
	}//end read()

	/**
	 * Write the broadcast in system context.
	 *
	 * @param array<string, mixed> $broadcast The complete broadcast, with its id when it exists
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return array<string, mixed> The stored broadcast, with its id
	 */
	private function write(array $broadcast): array {
		$uuid = null;
		if (isset($broadcast['id']) === true && $broadcast['id'] !== '') {
			$uuid = (string)$broadcast['id'];
		}

		unset($broadcast['id'], $broadcast['@self']);
		$saved = $this->objectService->saveObject(
			object: $broadcast,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
