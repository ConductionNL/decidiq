<?php

/**
 * Decidesk Action Item Analytics Service
 *
 * Service for computing action item analytics and KPI aggregations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for computing action item analytics and KPI metrics.
 *
 * Queries ActionItem objects from OpenRegister and computes summary metrics,
 * completion rates per meeting, and personal action item lists.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class ActionItemAnalyticsService
{
    /**
     * Constructor for ActionItemAnalyticsService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get a summary of action item metrics for a given date range.
     *
     * Returns: [ 'totalOpen' => int, 'totalOverdue' => int, 'completedThisMonth' => int, 'avgDaysToClose' => float ]
     *
     * @param string $dateFrom Start date (ISO 8601 format)
     * @param string $dateTo   End date (ISO 8601 format)
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     *
     * @return array<string, int|float>
     */
    public function getSummary(string $dateFrom, string $dateTo): array
    {
        try {
            /** @var \OCA\OpenRegister\Service\ObjectService $objectService */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $today = date('Y-m-d');
            $dateTo = $dateTo ?: $today;
            $dateFrom = $dateFrom ?: date('Y-01-01');

            // Query all action items
            $params = [
                '_limit' => 1000,
                '_offset' => 0,
            ];

            $allItems = [];
            $offset = 0;
            $limit = 100;

            do {
                $params['_offset'] = $offset;
                $params['_limit'] = $limit;
                $response = $objectService->findAll(
                    register: 'decidesk',
                    schema: 'ActionItem',
                    params: $params
                );

                if (empty($response['results'])) {
                    break;
                }

                $allItems = array_merge($allItems, $response['results']);
                $offset += $limit;
            } while (count($response['results']) === $limit);

            $totalOpen = 0;
            $totalOverdue = 0;
            $completedThisMonth = 0;
            $daysToCloseTotals = [];

            $currentMonth = date('Y-m');
            $today = new \DateTime('now', new \DateTimeZone('UTC'));

            foreach ($allItems as $item) {
                $dueDate = $item['dueDate'] ?? null;
                $taskStatus = $item['taskStatus'] ?? 'open';
                $createdAt = $item['createdAt'] ?? null;
                $completedAt = $item['completedAt'] ?? null;

                // Count open items
                if ($taskStatus !== 'completed') {
                    $totalOpen++;

                    // Count overdue items
                    if ($dueDate) {
                        try {
                            $due = new \DateTime($dueDate, new \DateTimeZone('UTC'));
                            if ($due < $today) {
                                $totalOverdue++;
                            }
                        } catch (\Exception) {
                            // Skip items with invalid dates
                        }
                    }
                }

                // Count completed this month
                if ($taskStatus === 'completed' && $completedAt) {
                    try {
                        $completed = new \DateTime($completedAt, new \DateTimeZone('UTC'));
                        if ($completed->format('Y-m') === $currentMonth) {
                            $completedThisMonth++;
                        }

                        // Calculate days to close
                        if ($createdAt) {
                            $created = new \DateTime($createdAt, new \DateTimeZone('UTC'));
                            $daysToClose = $completed->diff($created)->days;
                            $daysToCloseTotals[] = $daysToClose;
                        }
                    } catch (\Exception) {
                        // Skip items with invalid dates
                    }
                }
            }

            $avgDaysToClose = 0.0;
            if (!empty($daysToCloseTotals)) {
                $avgDaysToClose = (float)(array_sum($daysToCloseTotals) / count($daysToCloseTotals));
            }

            return [
                'totalOpen' => $totalOpen,
                'totalOverdue' => $totalOverdue,
                'completedThisMonth' => $completedThisMonth,
                'avgDaysToClose' => round($avgDaysToClose, 2),
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActionItemAnalyticsService: getSummary failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'totalOpen' => 0,
                'totalOverdue' => 0,
                'completedThisMonth' => 0,
                'avgDaysToClose' => 0.0,
            ];
        }
    }//end getSummary()

    /**
     * Get completion rates for the last N meetings.
     *
     * Returns array of [ 'meetingTitle' => string, 'completionRate' => float, 'total' => int ]
     *
     * @param int $limit Number of meetings to fetch (default: 6)
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     *
     * @return array<int, array<string, string|float|int>>
     */
    public function getCompletionRates(int $limit = 6): array
    {
        try {
            /** @var \OCA\OpenRegister\Service\ObjectService $objectService */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Get meetings ordered by date descending
            $params = [
                '_limit' => $limit,
                '_offset' => 0,
                '_order' => 'scheduledDate:desc',
            ];

            $response = $objectService->findAll(
                register: 'decidesk',
                schema: 'Meeting',
                params: $params
            );

            $meetings = $response['results'] ?? [];
            $rates = [];

            foreach ($meetings as $meeting) {
                $meetingId = $meeting['@self']['slug'] ?? $meeting['id'] ?? null;
                $meetingTitle = $meeting['title'] ?? 'Unknown Meeting';

                if (!$meetingId) {
                    continue;
                }

                // Query action items for this meeting (via relation)
                try {
                    $actionItemParams = [
                        '_limit' => 1000,
                        'meeting' => $meetingId,
                    ];

                    $itemsResponse = $objectService->findAll(
                        register: 'decidesk',
                        schema: 'ActionItem',
                        params: $actionItemParams
                    );

                    $items = $itemsResponse['results'] ?? [];

                    if (empty($items)) {
                        $rates[] = [
                            'meetingTitle' => $meetingTitle,
                            'completionRate' => 0.0,
                            'total' => 0,
                        ];
                        continue;
                    }

                    $totalItems = count($items);
                    $completedItems = 0;

                    foreach ($items as $item) {
                        if (($item['taskStatus'] ?? '') === 'completed') {
                            $completedItems++;
                        }
                    }

                    $completionRate = $totalItems > 0 ? ($completedItems / $totalItems) * 100 : 0;

                    $rates[] = [
                        'meetingTitle' => $meetingTitle,
                        'completionRate' => round($completionRate, 2),
                        'total' => $totalItems,
                    ];
                } catch (\Throwable $e) {
                    // Skip meetings with errors
                    $this->logger->warning(
                        'ActionItemAnalyticsService: failed to get items for meeting',
                        ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
                    );
                }
            }

            return $rates;
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActionItemAnalyticsService: getCompletionRates failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }//end getCompletionRates()

    /**
     * Get action items assigned to a specific user, grouped by status.
     *
     * Returns [ 'overdue' => [...], 'thisWeek' => [...], 'later' => [...] ]
     *
     * @param string $userDisplayName The display name of the assignee
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     *
     * @return array<string, array<int, array>>
     */
    public function getMyItems(string $userDisplayName): array
    {
        try {
            /** @var \OCA\OpenRegister\Service\ObjectService $objectService */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $params = [
                'assignee' => $userDisplayName,
                'taskStatus!' => 'completed',
                '_limit' => 1000,
                '_offset' => 0,
            ];

            $response = $objectService->findAll(
                register: 'decidesk',
                schema: 'ActionItem',
                params: $params
            );

            $items = $response['results'] ?? [];

            $today = new \DateTime('now', new \DateTimeZone('UTC'));
            $weekEnd = (clone $today)->modify('+7 days');

            $overdue = [];
            $thisWeek = [];
            $later = [];

            foreach ($items as $item) {
                $dueDate = $item['dueDate'] ?? null;

                if (!$dueDate) {
                    $later[] = $item;
                    continue;
                }

                try {
                    $due = new \DateTime($dueDate, new \DateTimeZone('UTC'));

                    if ($due < $today) {
                        $overdue[] = $item;
                    } elseif ($due <= $weekEnd) {
                        $thisWeek[] = $item;
                    } else {
                        $later[] = $item;
                    }
                } catch (\Exception) {
                    $later[] = $item;
                }
            }

            return [
                'overdue' => $overdue,
                'thisWeek' => $thisWeek,
                'later' => $later,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActionItemAnalyticsService: getMyItems failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'overdue' => [],
                'thisWeek' => [],
                'later' => [],
            ];
        }
    }//end getMyItems()
}//end class
