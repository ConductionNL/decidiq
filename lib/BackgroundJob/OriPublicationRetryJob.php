<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk ORI Publication Retry Job
 *
 * Background job that retries a failed ORI publication attempt.
 * Implements simple exponential backoff by re-enqueueing with an incremented
 * attempt counter; stops after three failed attempts.
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
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that retries a failed ORI publication attempt.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationRetryJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory          $time                  The time factory for scheduling
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IJobList              $jobList               The job list for re-enqueueing
     * @param LoggerInterface       $logger                The logger instance
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private OriPublicationService $oriPublicationService,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the retry job.
     *
     * Re-attempts ORI publication for the given VotingRound. If the attempt
     * limit is reached the job logs a permanent failure and stops retrying.
     *
     * @param mixed $argument The job argument: ['votingRoundId' => string, 'attempt' => int]
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        $votingRoundId = $argument['votingRoundId'] ?? '';
        $attempt       = (int) ($argument['attempt'] ?? 1);

        if ($votingRoundId === '') {
            $this->logger->error('OriPublicationRetryJob: missing votingRoundId in argument');
            return;
        }

        $this->logger->info(
            'OriPublicationRetryJob: retrying ORI publication',
            [
                'votingRoundId' => $votingRoundId,
                'attempt'       => $attempt,
            ]
        );

        $this->oriPublicationService->publishWithRetry(
            votingRoundId: $votingRoundId,
            attempt: $attempt,
            jobList: $this->jobList
        );
    }//end run()
}//end class
