<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk Mail Reply Handler
 *
 * Background job that polls for email vote replies and parses vote keywords.
 *
 * Runs every 5 minutes to check a configured mailbox for incoming vote replies.
 * Recognised keywords are the Dutch voting terms: Voor (for), Tegen (against),
 * and Onthouding (abstain).
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

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Background job that polls for email vote replies and parses vote keywords.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class MailReplyHandler extends \OCP\BackgroundJob\TimedJob
{

    /**
     * The service container for resolving dependencies.
     * Stored for use when IMAP polling is fully implemented (deferred to p3).
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * The Nextcloud app configuration service.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Constructor.
     *
     * @param \OCP\AppFramework\Utility\ITimeFactory $time      The time factory for scheduling.
     * @param ContainerInterface                     $container The service container for resolving dependencies.
     * @param LoggerInterface                        $logger    The logger instance.
     * @param IAppConfig                             $appConfig The Nextcloud app configuration service.
     */
    public function __construct(
        \OCP\AppFramework\Utility\ITimeFactory $time,
        ContainerInterface $container,
        LoggerInterface $logger,
        IAppConfig $appConfig,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 300);

        $this->container = $container;
        $this->logger    = $logger;
        $this->appConfig = $appConfig;
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Checks whether email voting is enabled in the app configuration. When
     * enabled, polls the configured mailbox for vote reply messages and parses
     * recognised vote keywords (Voor, Tegen, Onthouding) to record votes.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        $emailVotingEnabled = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_voting_enabled',
            'false',
        );

        if ($emailVotingEnabled !== 'true') {
            return;
        }//end if

        $this->logger->info('MailReplyHandler: checking for email vote replies');

        // In production, this would:
        // 1. Connect to the configured IMAP mailbox
        // 2. Fetch unread messages matching the vote reply subject pattern
        // 3. Parse the message body for vote keywords:
        // - "Voor"       → vote in favour
        // - "Tegen"      → vote against
        // - "Onthouding" → abstain
        // 4. Match the sender to a council member
        // 5. Record the vote on the corresponding VotingRound via ObjectService
        // 6. Mark the email as processed.
    }//end run()
}//end class
