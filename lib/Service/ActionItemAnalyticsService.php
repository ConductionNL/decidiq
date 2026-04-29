<?php

/**
 * Decidesk Action Item Analytics Service
 *
 * Service for computing action item analytics — completion rates, overdue counts,
 * and personal action item lists for the dashboard.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use DateTime;

/**
 * Stateless service that computes action item analytics at query time.
 *
 * Queries ActionItems via OpenRegister ObjectService with filters for
 * taskStatus and dueDate to compute summary metrics, per-meeting completion
 * rates, and personal action item lists grouped by urgency.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class ActionItemAnalyticsService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get a summary of action item metrics for a date range.
     *
     * Queries ActionItems with filters on taskStatus and dueDate; computes:
     * - totalOpen: count of items with taskStatus != 'completed'
     * - totalOverdue: count of items with dueDate < today and taskStatus != 'completed'
     * - completedThisMonth: count of items with completedAt in current month
     * - avgDaysToClose: average days between createdAt and completedAt for items
     *   completed within the date range
     *
     * @param string $dateFrom Start of range (ISO 8601 date)
     * @param string $dateTo   End of range (ISO 8601 date)
     *
     * @return array{
     *   totalOpen: int,
     *   totalOverdue: int,
     *   completedThisMonth: int,
     *   avgDaysToClose: float
     * } Summary metrics
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
     */
    public function getSummary(string $dateFrom, string $dateTo): array
    {
        // The four metrics are now declared on the ActionItem schema's
        // x-openregister-aggregations block. Each call hits OR's
        // AggregationRunner directly — no PHP-side iteration over every
        // ActionItem in this service.
        return [
            'totalOpen'          => $this->aggregate('totalOpen'),
            'totalOverdue'       => $this->aggregate('totalOverdue'),
            'completedThisMonth' => $this->aggregate('completedThisMonth'),
            'avgDaysToClose'     => round((float) $this->aggregate('avgDaysToClose'), 1),
        ];
    }//end getSummary()

    /**
     * Run a named aggregation declared on the ActionItem schema and
     * return its scalar value. Returns 0 on failure.
     */
    private function aggregate(string $name): float|int
    {
        try {
            $runner = $this->container->get('OCA\\OpenRegister\\Service\\Aggregation\\AggregationRunner');
            $result = $runner->run(registerRef: 'decidesk', schemaRef: 'action-item', name: $name);
            return $result['value'] ?? 0;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ActionItemAnalyticsService aggregation failed: '.$name.' — '.$e->getMessage()
            );
            return 0;
        }
    }//end aggregate()

    /**
     * Get per-meeting completion rates for the last N meetings.
     *
     * Fetches Meetings ordered by scheduledDate descending; for each Meeting,
     * queries linked ActionItems and computes completed / total * 100.
     *
     * @param int $limit Number of meetings to include (default 6)
     *
     * @return array<array{
     *   meetingTitle: string,
     *   completionRate: float,
     *   total: int
     * }> Array of completion rate objects
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
     */
    public function getCompletionRates(int $limit=6): array
    {
        try {
            $objectService = $this->container->get('OpenRegisterObjectService');

            // Get recent meetings.
            $params   = [
                '_limit'  => $limit,
                '_offset' => 0,
                '_order'  => 'scheduledDate:DESC',
            ];
            $meetings = $objectService->findObjects(
                register: 'decidesk',
                schema: 'Meeting',
                params: $params
            );

            $rates = [];

            foreach ($meetings as $meeting) {
                $meetingId = $meeting['id'] ?? $meeting['@self']['id'] ?? null;
                if ($meetingId === null) {
                    continue;
                }

                // Find action items linked to this meeting (via relations).
                $actionItems = [];
                if (empty($meeting['relations']['ActionItem']) === false) {
                    foreach ($meeting['relations']['ActionItem'] as $link) {
                        $actionItems[] = $link;
                    }
                }

                if (empty($actionItems) === true) {
                    $rates[] = [
                        'meetingTitle'   => $meeting['title'] ?? 'Untitled Meeting',
                        'completionRate' => 0,
                        'total'          => 0,
                    ];
                    continue;
                }

                // Count completed vs total.
                $completed = 0;
                foreach ($actionItems as $item) {
                    if (($item['taskStatus'] ?? null) === 'completed') {
                        $completed++;
                    }
                }

                $total = count($actionItems);
                if ($total > 0) {
                    $rate = ($completed / $total * 100);
                } else {
                    $rate = 0;
                }

                $rates[] = [
                    'meetingTitle'   => $meeting['title'] ?? 'Untitled Meeting',
                    'completionRate' => round($rate, 1),
                    'total'          => $total,
                ];
            }//end foreach

            return $rates;
        } catch (\Exception $e) {
            $this->logger->error('ActionItemAnalyticsService::getCompletionRates failed: '.$e->getMessage());

            return [];
        }//end try
    }//end getCompletionRates()

    /**
     * Get action items assigned to the current user, grouped by urgency.
     *
     * Queries ActionItems where assignee matches the user display name and
     * taskStatus != 'completed'; groups into:
     * - overdue: dueDate < today
     * - thisWeek: dueDate <= today + 7 days
     * - later: dueDate > today + 7 days
     *
     * @param string $userDisplayName User's display name
     *
     * @return array<string, array<array>> Grouped array with keys 'overdue', 'thisWeek', 'later'
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
     */
    public function getMyItems(string $userDisplayName): array
    {
        try {
            $objectService = $this->container->get('OpenRegisterObjectService');
            $today         = new DateTime();
            $weekAhead     = new DateTime('+7 days');

            // Query action items assigned to this user.
            $params = [
                'assignee'   => $userDisplayName,
                'taskStatus' => ['open', 'in-progress'],
            // Exclude completed.
                '_limit'     => 999,
                '_offset'    => 0,
            ];
            $items = $objectService->findObjects(
                register: 'decidesk',
                schema: 'ActionItem',
                params: $params
            );

            $grouped = [
                'overdue'  => [],
                'thisWeek' => [],
                'later'    => [],
            ];

            foreach ($items as $item) {
                if (empty($item['dueDate']) === true) {
                    $grouped['later'][] = $item;
                    continue;
                }

                $dueDate = new DateTime($item['dueDate']);

                if ($dueDate < $today) {
                    $grouped['overdue'][] = $item;
                } else if ($dueDate <= $weekAhead) {
                    $grouped['thisWeek'][] = $item;
                } else {
                    $grouped['later'][] = $item;
                }
            }

            return $grouped;
        } catch (\Exception $e) {
            $this->logger->error('ActionItemAnalyticsService::getMyItems failed: '.$e->getMessage());

            return [
                'overdue'  => [],
                'thisWeek' => [],
                'later'    => [],
            ];
        }//end try
    }//end getMyItems()
}//end class
