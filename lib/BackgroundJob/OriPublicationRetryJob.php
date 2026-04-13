<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk ORI Publication Retry Job
 *
 * Queued background job that retries a failed ORI publication attempt.
 * Added to the job queue by OriPublicationService when an initial publish
 * call fails. Maximum 3 total attempts are tracked on the VotingRound object.
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
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\Service\OriPublicationService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queued background job that retries a failed ORI publication.
 *
 * Uses lazy container resolution for OriPublicationService to avoid a
 * circular construction dependency (OriPublicationService → IJobList →
 * OriPublicationRetryJob → OriPublicationService).
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationRetryJob extends \OCP\BackgroundJob\QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      The time factory for job scheduling.
     * @param ContainerInterface $container The DI container for lazy service resolution.
     * @param LoggerInterface    $logger    The logger instance.
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the retry job.
     *
     * Resolves OriPublicationService lazily from the container to avoid
     * circular construction, then calls publish() with the stored votingRoundId.
     *
     * @param mixed $argument The job argument array containing 'votingRoundId'.
     *
     * @return void
     */
    protected function run($argument): void
    {
        $votingRoundId = $argument['votingRoundId'] ?? null;
        if ($votingRoundId === null) {
            $this->logger->warning('OriPublicationRetryJob: missing votingRoundId in argument');
            return;
        }

        $this->logger->info(
            'OriPublicationRetryJob: retrying ORI publication',
            ['votingRoundId' => $votingRoundId]
        );

        /*
         * @var OriPublicationService $service
         */

        $service = $this->container->get(OriPublicationService::class);
        $service->publish($votingRoundId);
    }//end run()
}//end class
