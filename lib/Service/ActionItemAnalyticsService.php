<?php

/**
 * Decidiq Action Item Analytics Service
 *
 * Personal action-item list service.  Generic aggregation metrics (completion
 * rate, overdue counts) moved to x-openregister-aggregations on the Meeting
 * schema and are rendered by the ADR-019 analytics integration leaf.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/changes/archive/2026-06-10-migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service providing personal action-item lists grouped by urgency.
 *
 * Generic dashboard aggregations (completion rate, overdue counts, per-meeting
 * rates) have been migrated to the analytics integration leaf via
 * x-openregister-aggregations on the Meeting schema (ADR-031, ADR-019).
 * This service retains only getMyItems() — a personal, urgency-grouped task
 * list that the generic leaf cannot compute from raw OR data (it requires NC
 * UID → Participant UUID resolution, a domain-specific lookup step).
 *
 * @spec openspec/changes/archive/2026-06-10-migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
 */
class ActionItemAnalyticsService {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 */
	public function __construct(
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get action items assigned to the current user, grouped by urgency.
	 *
	 * Queries ActionItems where assignee matches the Participant UUID resolved
	 * from the caller's Nextcloud UID, then taskStatus != 'completed'; groups into:
	 * - overdue: dueDate < today
	 * - thisWeek: dueDate <= today + 7 days
	 * - later: dueDate > today + 7 days
	 *
	 * Using NC UID (not display name) prevents cross-user PII leaks via display name
	 * spoofing (display names are user-settable and non-unique).
	 *
	 * @param string $nextcloudUid The caller's Nextcloud user UID
	 *
	 * @return array<string, array<array>> Grouped array with keys 'overdue', 'thisWeek', 'later'
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	public function getMyItems(string $nextcloudUid): array {
		try {
			return $this->groupedItemsOf(nextcloudUid: $nextcloudUid);
		} catch (\Exception $e) {
			$this->logger->error('ActionItemAnalyticsService::getMyItems failed: ' . $e->getMessage());

			return self::emptyGroups();
		}//end try
	}//end getMyItems()

	/**
	 * The open action items of the caller's participant, grouped by urgency.
	 *
	 * @param string $nextcloudUid The caller's Nextcloud user UID
	 *
	 * @return array<string, array<array>> Grouped array with keys 'overdue', 'thisWeek', 'later'
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	private function groupedItemsOf(string $nextcloudUid): array {
		$today = new DateTime();
		$participantId = $this->participantIdOf(nextcloudUid: $nextcloudUid);
		if ($participantId === null) {
			// No participant record found — return empty result.
			$this->logger->info(
				'ActionItemAnalyticsService::getMyItems: no participant found',
				['nextcloudUid' => $nextcloudUid]
			);
			return self::emptyGroups();
		}

		$grouped = self::emptyGroups();
		foreach ($this->openItemsOf(participantId: $participantId) as $itemEntity) {
			$item = $itemEntity->jsonSerialize();
			$grouped[$this->urgencyOf(item: $item, today: $today)][] = $item;
		}

		return $grouped;
	}//end groupedItemsOf()

	/**
	 * The Participant UUID of a Nextcloud user, the canonical pattern used by
	 * VotingController/VotingBehaviourController.
	 *
	 * @param string $nextcloudUid The caller's Nextcloud user UID
	 *
	 * @return string|null The participant's uuid (or id), null when there is none.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	private function participantIdOf(string $nextcloudUid): ?string {
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema('participant');
		$participantEntities = $this->objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

		foreach ($participantEntities as $pEntity) {
			$pData = $pEntity->jsonSerialize();
			return $pData['uuid'] ?? ($pData['id'] ?? null);
		}

		return null;
	}//end participantIdOf()

	/**
	 * The action items assigned to a participant that are not completed.
	 *
	 * @param string $participantId The participant's uuid
	 *
	 * @return array<int, mixed> The item entities.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	private function openItemsOf(string $participantId): array {
		$params = [
			'assignee' => $participantId,
			'taskStatus' => ['open', 'in-progress'],
			// Exclude completed.
			'_limit' => 999,
			'_offset' => 0,
		];
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema('action-item');
		return $this->objectService->findAll(['filters' => $params]);
	}//end openItemsOf()

	/**
	 * The group an item falls in: overdue before today, thisWeek within seven
	 * days from now, later after that or without a due date.
	 *
	 * @param array<string, mixed> $item  The action item
	 * @param DateTime             $today Now
	 *
	 * @return string overdue|thisWeek|later
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	private function urgencyOf(array $item, DateTime $today): string {
		if (empty($item['dueDate']) === true) {
			return 'later';
		}

		$dueDate = new DateTime($item['dueDate']);
		if ($dueDate < $today) {
			return 'overdue';
		}

		if ($dueDate <= (clone $today)->modify('+7 days')) {
			return 'thisWeek';
		}

		return 'later';
	}//end urgencyOf()

	/**
	 * The three empty urgency groups.
	 *
	 * @return array<string, array<array>> The groups.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	private static function emptyGroups(): array {
		return [
			'overdue' => [],
			'thisWeek' => [],
			'later' => [],
		];
	}//end emptyGroups()
}//end class
