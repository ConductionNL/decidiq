<?php
/**
 * Decidesk Overdue Action Items Background Job
 *
 * Daily background job that detects overdue ActionItems and updates their status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily background job that queries open/in-progress ActionItems with a past dueDate
 * and transitions their taskStatus to "overdue" via ObjectService::saveObject().
 *
 * The frontend also calculates and displays overdue state client-side
 * (dueDate < today) for immediate feedback; this job is a best-effort
 * persistent status sync. Job errors are logged to the Nextcloud log.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 */
class OverdueActionItemsJob extends TimedJob
{

    /**
     * Interval between job runs: 86400 seconds = 24 hours.
     */
    private const INTERVAL_SECONDS = 86400;

    /**
     * Constructor for OverdueActionItemsJob.
     *
     * @param ITimeFactory       $time      Nextcloud time factory (injected by TimedJob)
     * @param ContainerInterface $container The DI container (lazy-loads OpenRegister services)
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * Execute the overdue detection logic.
     *
     * Queries all ActionItems with taskStatus "open" or "in-progress" and a dueDate
     * in the past, then sets taskStatus to "overdue" for each via ObjectService.
     *
     * @param mixed $argument Not used; required by TimedJob interface.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('Decidesk: OverdueActionItemsJob started');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk OverdueActionItemsJob: OpenRegister not available, skipping.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $updatedCount = 0;
        $errorCount   = 0;

        foreach (['open', 'in-progress'] as $status) {
            $actionItems = $this->fetchActionItemsByStatus(objectService: $objectService, status: $status);

            foreach ($actionItems as $item) {
                $dueDate = $item['dueDate'] ?? null;
                if ($dueDate === null || $dueDate === '') {
                    // No dueDate — cannot be overdue.
                    continue;
                }

                try {
                    $dueDatetime = new \DateTimeImmutable($dueDate);
                    $nowDatetime = new \DateTimeImmutable($now);
                } catch (\Throwable) {
                    // Unparseable dueDate — skip.
                    continue;
                }

                if ($dueDatetime >= $nowDatetime) {
                    // Not yet overdue.
                    continue;
                }

                $uuid = $item['id'] ?? $item['uuid'] ?? null;
                if ($uuid === null || $uuid === '') {
                    continue;
                }

                try {
                    $objectService->setRegister('decidesk');
                    $objectService->setSchema('action-item');
                    $objectService->saveObject(
                        array_merge($item, ['taskStatus' => 'overdue']),
                        'decidesk',
                        'action-item',
                        $uuid
                    );
                    $updatedCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->logger->error(
                        'Decidesk OverdueActionItemsJob: Failed to update ActionItem to overdue',
                        ['uuid' => $uuid, 'exception' => $e->getMessage()]
                    );
                }//end try
            }//end foreach
        }//end foreach

        $this->logger->info(
            sprintf(
                'Decidesk OverdueActionItemsJob: marked %d ActionItems as overdue (%d errors)',
                $updatedCount,
                $errorCount
            )
        );

    }//end run()

    /**
     * Fetch all ActionItems matching a given taskStatus from OpenRegister.
     *
     * Uses offset-based pagination so that installations with more than
     * PAGE_SIZE items are fully traversed rather than silently truncated.
     *
     * @param object $objectService The ObjectService instance
     * @param string $status        The taskStatus to filter on ("open" or "in-progress")
     *
     * @return array<int,array<string,mixed>> Array of ActionItem data arrays
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    private function fetchActionItemsByStatus(object $objectService, string $status): array
    {
        $pageSize = 100;
        $offset   = 0;
        $result   = [];

        while (true) {
            try {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('action-item');
                $entities = $objectService->findAll(
                        [
                            'filters' => [
                                'register'   => 'decidesk',
                                'schema'     => 'action-item',
                                'taskStatus' => $status,
                            ],
                            'limit'   => $pageSize,
                            'offset'  => $offset,
                        ]
                        );

                $batch = [];
                foreach ($entities as $entity) {
                    if (method_exists($entity, 'getObject') === true) {
                        $batch[] = $entity->getObject();
                    } else if (is_array($entity) === true) {
                        $batch[] = $entity;
                    }
                }

                $result  = array_merge($result, $batch);
                $offset += count($batch);

                // Fewer results than page size means we have reached the last page.
                if (count($batch) < $pageSize) {
                    break;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Decidesk OverdueActionItemsJob: Failed to fetch ActionItems',
                    ['status' => $status, 'offset' => $offset, 'exception' => $e->getMessage()]
                );
                break;
            }//end try
        }//end while

        return $result;

    }//end fetchActionItemsByStatus()
}//end class
