<?php

/**
 * Overdue Action Items Background Job
 *
 * Daily job that marks overdue action items.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily background job that detects overdue action items.
 *
 * Queries all ActionItems where taskStatus is 'open' or 'in-progress'
 * and dueDate < now(), then updates their taskStatus to 'overdue'.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
 */
class OverdueActionItemsJob extends TimedJob
{

    /**
     * Run interval: once per day (24 hours in seconds).
     *
     * @var int
     */
    private const RUN_INTERVAL = 86400;

    /**
     * Constructor for OverdueActionItemsJob.
     *
     * @param ITimeFactory       $time      The time factory
     * @param IAppConfig         $appConfig The app config
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::RUN_INTERVAL);
    }//end __construct()

    /**
     * Execute the overdue detection job.
     *
     * @param mixed $argument Job argument (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('Decidesk: OverdueActionItemsJob started');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: ObjectService not available', ['exception' => $e->getMessage()]);
            return;
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'decidesk');
        $now      = (new \DateTime())->format('c');
        $updated  = 0;

        foreach (['open', 'in-progress'] as $status) {
            try {
                $result = $objectService->findObjects(
                    register: $register,
                    schema: 'action-item',
                    params: ['taskStatus' => $status]
                );
                $items = ($result['results'] ?? $result ?? []);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to query action items with status '.$status,
                    ['exception' => $e->getMessage()]
                );
                continue;
            }

            foreach ($items as $item) {
                $dueDate = ($item['dueDate'] ?? null);
                if ($dueDate === null) {
                    continue;
                }

                if (strtotime($dueDate) < strtotime($now)) {
                    try {
                        $item['taskStatus'] = 'overdue';
                        $objectService->saveObject(
                            register: $register,
                            schema: 'action-item',
                            object: $item
                        );
                        $updated++;
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            'Decidesk: failed to update action item to overdue',
                            [
                                'id'        => ($item['id'] ?? 'unknown'),
                                'exception' => $e->getMessage(),
                            ]
                        );
                    }
                }
            }//end foreach
        }//end foreach

        $this->logger->info('Decidesk: OverdueActionItemsJob completed, updated '.$updated.' items');
    }//end run()
}//end class
