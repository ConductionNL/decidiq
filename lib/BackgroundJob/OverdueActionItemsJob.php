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

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily background job that queries ActionItems where taskStatus is 'open' or
 * 'in-progress' and dueDate is in the past, then sets taskStatus to 'overdue'.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 */
class OverdueActionItemsJob extends TimedJob
{

    /** Run once per day (86400 seconds). */
    private const INTERVAL_SECONDS = 86400;

    /**
     * Constructor for OverdueActionItemsJob.
     *
     * @param ITimeFactory       $time      The Nextcloud time factory
     * @param ContainerInterface $container The DI container (for lazy OpenRegister services)
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * Execute the overdue detection logic.
     *
     * Queries all ActionItems with taskStatus 'open' or 'in-progress' and a
     * dueDate in the past, then updates each one to taskStatus 'overdue' via
     * OpenRegister's ObjectService.
     *
     * @param array $argument Job arguments (unused)
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
                'Decidesk: OpenRegister ObjectService unavailable, skipping overdue detection',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $now     = new \DateTimeImmutable();
        $updated = 0;
        $errors  = 0;

        foreach (['open', 'in-progress'] as $status) {
            try {
                $items = $objectService->getObjects(
                    register: 'decidesk',
                    schema: 'action-item',
                    filters: ['taskStatus' => $status],
                    limit: 1000
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Decidesk: failed to fetch ActionItems with status '$status'",
                    ['exception' => $e->getMessage()]
                );
                continue;
            }

            foreach (($items['results'] ?? $items ?? []) as $item) {
                $dueDate = $item['dueDate'] ?? null;
                if (empty($dueDate) === true) {
                    continue;
                }

                try {
                    $dueDateObj = new \DateTimeImmutable($dueDate);
                } catch (\Throwable $e) {
                    continue;
                }

                if ($dueDateObj >= $now) {
                    continue;
                }

                $id = $item['id'] ?? $item['uuid'] ?? null;
                if ($id === null) {
                    continue;
                }

                try {
                    $objectService->saveObject(
                        register: 'decidesk',
                        schema: 'action-item',
                        object: array_merge($item, ['taskStatus' => 'overdue']),
                        id: (string) $id
                    );
                    $updated++;
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Decidesk: failed to mark ActionItem as overdue',
                        ['id' => $id, 'exception' => $e->getMessage()]
                    );
                    $errors++;
                }
            }//end foreach
        }//end foreach

        $this->logger->info(
            "Decidesk: OverdueActionItemsJob finished — {$updated} items marked overdue, {$errors} errors"
        );

    }//end run()

}//end class
