<?php

/**
 * Decidiq Board Book Job State
 *
 * Where a meeting's pack compilation stands (queued, running, done, failed)
 * and the last run's outcome, kept in the distributed cache for the status
 * endpoint, or in the app config when the instance has no distributed cache:
 * the compilation runs in a cron process and the status is read in a web
 * request, so the store must be shared between processes. Without that
 * fallback an instance without Redis read every pack as idle for ever (found
 * live on 25 Nov 2026). It is a progress report, not a record: the packs themselves are
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

use OCP\IAppConfig;
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
	 * The app config, used when there is no distributed cache.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Whether the distributed cache is shared between processes.
	 *
	 * @var boolean
	 */
	private readonly bool $shared;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $caches    The cache factory.
	 * @param IAppConfig    $appConfig The app config, the store without a distributed cache.
	 */
	public function __construct(ICacheFactory $caches, IAppConfig $appConfig) {
		$this->cache     = $caches->createDistributed('decidiq-board-book');
		$this->shared    = $caches->isAvailable();
		$this->appConfig = $appConfig;
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
		$value = (string)json_encode(['state' => $state, 'outcome' => $outcome, 'at' => time()]);
		if ($this->shared === true) {
			$this->cache->set($this->key(meetingId: $meetingId), $value, self::TTL);
			return;
		}

		$this->appConfig->setValueString('decidiq', $this->key(meetingId: $meetingId), $value, lazy: true);
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
		$decoded = json_decode($this->stored(meetingId: $meetingId), true);
		if (is_array($decoded) === false || is_string($decoded['state'] ?? null) === false) {
			return ['state' => 'idle', 'outcome' => []];
		}

		// The app config keeps what the cache would have let expire.
		if ($this->shared === false && (int)($decoded['at'] ?? 0) < (time() - self::TTL)) {
			$this->appConfig->deleteKey('decidiq', $this->key(meetingId: $meetingId));
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
	 * The stored state of a meeting, '' when there is none.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	private function stored(string $meetingId): string {
		if ($this->shared === true) {
			return (string)$this->cache->get($this->key(meetingId: $meetingId));
		}

		return $this->appConfig->getValueString('decidiq', $this->key(meetingId: $meetingId), '', lazy: true);
	}//end stored()

	/**
	 * The cache key of a meeting.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return string
	 */
	private function key(string $meetingId): string {
		return 'board_book_state_' . $meetingId;
	}//end key()
}//end class
