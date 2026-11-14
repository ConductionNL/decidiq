<?php

/**
 * Decidiq Board Book Job State
 *
 * Where a meeting's pack compilation stands (queued, running, done, failed)
 * and the last run's outcome, kept in the distributed cache for the status
 * endpoint. It is a progress report, not a record: the packs themselves are
 * MeetingPack objects, and a lost entry reads as idle.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\BoardBook;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The compile state per meeting.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
 */
class BoardBookJobState {

	/**
	 * How long a state is kept, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 86400;

	/**
	 * The states in which a new compilation is refused.
	 *
	 * @var array<int, string>
	 */
	public const IN_FLIGHT = ['queued', 'running'];

	/**
	 * The cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $caches The cache factory.
	 */
	public function __construct(ICacheFactory $caches) {
		$this->cache = $caches->createDistributed('decidiq-board-book');
	}//end __construct()

	/**
	 * Record a meeting's state and, when it finished, its outcome.
	 *
	 * @param string               $meetingId UUID of the meeting
	 * @param string               $state     queued, running, done or failed
	 * @param array<string, mixed> $outcome   The run's outcome
	 *
	 * @return void
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function set(string $meetingId, string $state, array $outcome=[]): void {
		$this->cache->set($this->key(meetingId: $meetingId), json_encode(['state' => $state, 'outcome' => $outcome]), self::TTL);
	}//end set()

	/**
	 * A meeting's state and last outcome; idle when nothing is recorded.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return array{state: string, outcome: array<string, mixed>}
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function get(string $meetingId): array {
		$decoded = json_decode((string)$this->cache->get($this->key(meetingId: $meetingId)), true);
		if (is_array($decoded) === false || is_string($decoded['state'] ?? null) === false) {
			return ['state' => 'idle', 'outcome' => []];
		}

		return ['state' => $decoded['state'], 'outcome' => (array)($decoded['outcome'] ?? [])];
	}//end get()

	/**
	 * Whether a compilation is queued or running for the meeting.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function inFlight(string $meetingId): bool {
		return in_array($this->get(meetingId: $meetingId)['state'], self::IN_FLIGHT, true);
	}//end inFlight()

	/**
	 * The cache key of a meeting.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return string
	 */
	private function key(string $meetingId): string {
		return 'state-' . $meetingId;
	}//end key()
}//end class
