<?php

/**
 * Decidesk Action Item Analytics Service
 *
 * Personal action-item list service.  Generic aggregation metrics (completion
 * rate, overdue counts) moved to x-openregister-aggregations on the Meeting
 * schema and are rendered by the ADR-019 analytics integration leaf.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTime;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
 */
class ActionItemAnalyticsService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
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
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	public function getMyItems(string $nextcloudUid): array {
		try {
			$today = new DateTime();
			$weekAhead = new DateTime('+7 days');

			// Resolve the Participant UUID for this Nextcloud user so we can filter
			// action items by the participant UUID stored in the assignee field.
			// This is the canonical pattern used by VotingController/VotingBehaviourController.
			$this->objectService->setRegister('decidesk');
			$this->objectService->setSchema('participant');
			$participantEntities = $this->objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

			$participantId = null;
			foreach ($participantEntities as $pEntity) {
				$pData = $pEntity->jsonSerialize();
				$participantId = $pData['uuid'] ?? ($pData['id'] ?? null);
				break;
			}

			if ($participantId === null) {
				// No participant record found — return empty result.
				$this->logger->info(
					'ActionItemAnalyticsService::getMyItems: no participant found',
					['nextcloudUid' => $nextcloudUid]
				);
				return [
					'overdue' => [],
					'thisWeek' => [],
					'later' => [],
				];
			}

			// Query action items assigned to this participant UUID.
			$params = [
				'assignee' => $participantId,
				'taskStatus' => ['open', 'in-progress'],
				// Exclude completed.
				'_limit' => 999,
				'_offset' => 0,
			];
			$this->objectService->setRegister('decidesk');
			$this->objectService->setSchema('action-item');
			$itemEntities = $this->objectService->findAll(['filters' => $params]);

			$grouped = [
				'overdue' => [],
				'thisWeek' => [],
				'later' => [],
			];

			foreach ($itemEntities as $itemEntity) {
				$item = $itemEntity->jsonSerialize();
				if (empty($item['dueDate']) === true) {
					$grouped['later'][] = $item;
					continue;
				}

				$dueDate = new DateTime($item['dueDate']);

				if ($dueDate < $today) {
					$grouped['overdue'][] = $item;
					continue;
				}

				if ($dueDate <= $weekAhead) {
					$grouped['thisWeek'][] = $item;
					continue;
				}

				$grouped['later'][] = $item;
			}//end foreach

			return $grouped;
		} catch (\Exception $e) {
			$this->logger->error('ActionItemAnalyticsService::getMyItems failed: ' . $e->getMessage());

			return [
				'overdue' => [],
				'thisWeek' => [],
				'later' => [],
			];
		}//end try
	}//end getMyItems()
}//end class
