<?php

/**
 * Decidesk Voting Deadline Reminder Job
 *
 * Hourly background job delegating to VotingDeadlineReminderService
 * (nextcloud-integration spec, notification requirement).
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\Service\VotingDeadlineReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly sweep for 24-hour pre-deadline voting reminders. Registered in
 * appinfo/info.xml <background-jobs> (the proven decidesk job pattern).
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingDeadlineReminderJob extends TimedJob
{

    /**
     * Interval between job runs: 3600 seconds = 1 hour.
     *
     * @var int
     */
    private const INTERVAL_SECONDS = 3600;

    /**
     * Constructor for VotingDeadlineReminderJob.
     *
     * @param ITimeFactory                  $time            Nextcloud time factory (injected by TimedJob)
     * @param VotingDeadlineReminderService $reminderService The reminder sweep service
     * @param LoggerInterface               $logger          The logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly VotingDeadlineReminderService $reminderService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(self::INTERVAL_SECONDS);

    }//end __construct()

    /**
     * Run the reminder sweep.
     *
     * @param mixed $argument Unused job argument
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        try {
            $sent = $this->reminderService->run(now: $this->time->getTime());
            if ($sent > 0) {
                $this->logger->info('Decidesk: voting deadline reminder job sent notifications', ['sent' => $sent]);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: voting deadline reminder job failed',
                ['exception' => $e->getMessage()]
            );
        }

    }//end run()
}//end class
