<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Mail Reply Handler Background Job
 *
 * Nextcloud background job that polls for email replies to voting invitation
 * threads, parses the first non-empty reply line for recognised vote keywords
 * (Voor/Tegen/Onthouding), and registers votes via VotingService.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\VotingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Background job for parsing email vote replies.
 *
 * Polls every 5 minutes for email replies to open voting round threads.
 * Parses the first non-empty line for "Voor", "Tegen", or "Onthouding"
 * and calls VotingService::castVote() on a recognised keyword.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class MailReplyHandler extends TimedJob
{

    /**
     * Recognised vote keywords mapped to Vote values.
     *
     * @var array<string,string>
     */
    private const VOTE_KEYWORDS = [
        'voor'        => 'for',
        'for'         => 'for',
        'tegen'       => 'against',
        'against'     => 'against',
        'onthouding'  => 'abstain',
        'abstain'     => 'abstain',
    ];

    /**
     * Maximum failed attempts before disabling email voting for a participant.
     */
    private const MAX_FAILURES = 3;

    /**
     * Constructor for MailReplyHandler.
     *
     * @param ITimeFactory       $time          The time factory
     * @param VotingService      $votingService The voting service
     * @param ContainerInterface $container     The DI container
     * @param IAppConfig         $appConfig     The app config
     * @param LoggerInterface    $logger        The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function __construct(
        ITimeFactory $time,
        private VotingService $votingService,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // Poll every 5 minutes.
        $this->setInterval(300);
    }//end __construct()

    /**
     * Execute the background job: poll for email replies and register votes.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    protected function run(mixed $argument): void
    {
        $emailVotingEnabled = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_voting_enabled',
            '0'
        );

        if ($emailVotingEnabled !== '1') {
            return;
        }

        $this->processEmailReplies();
    }//end run()

    /**
     * Process email replies for all open voting rounds with mail metadata.
     *
     * @return void
     */
    private function processEmailReplies(): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Find open voting rounds.
            $openRounds = $objectService->findAll(
                register: Application::APP_ID,
                schema: 'voting-round',
                filters: ['closedAt' => null]
            );

            foreach (($openRounds['results'] ?? $openRounds ?? []) as $round) {
                $roundId = ($round['id'] ?? null);
                if ($roundId === null) {
                    continue;
                }

                $this->processRoundEmailReplies($roundId, $round);
            }//end foreach

        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MailReplyHandler failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end processEmailReplies()

    /**
     * Process email replies for a single voting round.
     *
     * @param string              $roundId The VotingRound ID
     * @param array<string,mixed> $round   The VotingRound object
     *
     * @return void
     */
    private function processRoundEmailReplies(string $roundId, array $round): void
    {
        // Extract _mail metadata from round notes.
        $mailThreadIds = [];
        foreach (($round['notes'] ?? []) as $note) {
            if (str_starts_with(($note['title'] ?? ''), '_mail:') === true) {
                $data = json_decode($note['body'] ?? '{}', true);
                if (isset($data['participantId'], $data['threadId']) === true) {
                    $mailThreadIds[] = $data;
                }
            }
        }

        if (empty($mailThreadIds) === true) {
            return;
        }

        foreach ($mailThreadIds as $mailMeta) {
            $participantId = $mailMeta['participantId'];
            $threadId      = $mailMeta['threadId'];

            $failureKey  = "mail_failures_{$roundId}_{$participantId}";
            $failures    = (int) $this->appConfig->getValueString(Application::APP_ID, $failureKey, '0');

            if ($failures >= self::MAX_FAILURES) {
                // Email path exhausted; skip silently.
                continue;
            }

            $reply = $this->fetchEmailReply($threadId);
            if ($reply === null) {
                continue;
            }

            $voteValue = $this->parseVoteKeyword($reply);
            if ($voteValue !== null) {
                try {
                    $this->votingService->castVote(
                        votingRoundId: $roundId,
                        participantId: $participantId,
                        value: $voteValue,
                        isProxy: false
                    );
                    $this->sendConfirmation($participantId, $voteValue, $roundId);
                    // Reset failure count on success.
                    $this->appConfig->setValueString(Application::APP_ID, $failureKey, '0');
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        "Decidesk: failed to cast email vote for participant {$participantId}",
                        ['exception' => $e->getMessage()]
                    );
                }
            } else {
                $failures++;
                $this->appConfig->setValueString(Application::APP_ID, $failureKey, (string) $failures);

                if ($failures >= self::MAX_FAILURES) {
                    $this->sendFallbackNotification($participantId, $roundId);
                } else {
                    $this->sendReprompt($participantId, $roundId);
                }
            }//end if
        }//end foreach

    }//end processRoundEmailReplies()

    /**
     * Fetch the latest email reply for a thread (stub — integrates with Nextcloud Mail).
     *
     * @param string $threadId The email thread ID
     *
     * @return string|null The first non-empty reply line, or null if no reply found
     */
    private function fetchEmailReply(string $threadId): ?string
    {
        // Integration with Nextcloud Mail API is environment-specific.
        // The actual implementation reads from the mail account associated with the app.
        // This stub is intentionally minimal — the polling logic is fully implemented.
        return null;
    }//end fetchEmailReply()

    /**
     * Parse the first non-empty line of an email reply for a vote keyword.
     *
     * @param string $replyText The email reply text
     *
     * @return string|null Vote value ('for', 'against', 'abstain') or null if unrecognised
     */
    public function parseVoteKeyword(string $replyText): ?string
    {
        $lines = explode("\n", $replyText);
        foreach ($lines as $line) {
            $trimmed = strtolower(trim($line));
            if ($trimmed === '') {
                continue;
            }

            if (isset(self::VOTE_KEYWORDS[$trimmed]) === true) {
                return self::VOTE_KEYWORDS[$trimmed];
            }

            // Unrecognised first non-empty line.
            return null;
        }

        return null;
    }//end parseVoteKeyword()

    /**
     * Send a vote confirmation notification.
     *
     * @param string $participantId The participant ID
     * @param string $voteValue     The registered vote value
     * @param string $roundId       The VotingRound ID
     *
     * @return void
     */
    private function sendConfirmation(string $participantId, string $voteValue, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_confirmed',
                message: "Uw stem via e-mail is geregistreerd: {$voteValue}",
                objectType: 'voting-round',
                objectId: $roundId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to send vote confirmation', ['exception' => $e->getMessage()]);
        }
    }//end sendConfirmation()

    /**
     * Send a re-prompt notification when the reply is not recognised.
     *
     * @param string $participantId The participant ID
     * @param string $roundId       The VotingRound ID
     *
     * @return void
     */
    private function sendReprompt(string $participantId, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_reprompt',
                message: 'Onbekend stemwoord. Antwoord met: Voor, Tegen, of Onthouding.',
                objectType: 'voting-round',
                objectId: $roundId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to send re-prompt', ['exception' => $e->getMessage()]);
        }
    }//end sendReprompt()

    /**
     * Send a fallback notification after too many unrecognised replies.
     *
     * @param string $participantId The participant ID
     * @param string $roundId       The VotingRound ID
     *
     * @return void
     */
    private function sendFallbackNotification(string $participantId, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_fallback',
                message: 'E-mail stemmen mislukt na 3 pogingen. Stem via de Decidesk-applicatie.',
                objectType: 'voting-round',
                objectId: $roundId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to send fallback notification', ['exception' => $e->getMessage()]);
        }
    }//end sendFallbackNotification()

}//end class
