<?php

/**
 * Decidesk Mail Reply Handler Background Job
 *
 * Background job that polls for email replies to voting invitation threads
 * and registers votes via VotingService::castVote().
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
 * Background job for parsing email replies to voting invitations.
 *
 * Polls open VotingRounds with email voting enabled, reads replies from
 * Nextcloud Mail via _mail metadata, parses the first non-empty line for
 * Dutch vote keywords, and registers the vote via VotingService::castVote().
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailReplyHandler extends TimedJob
{

    /**
     * Dutch vote keywords (case-insensitive).
     *
     * @var array<string, string>
     */
    private const VOTE_KEYWORDS = [
        'voor'         => 'for',
        'tegen'        => 'against',
        'onthouding'   => 'abstain',
    ];

    /**
     * Maximum failed parsing attempts before abandoning email voting per participant/round.
     *
     * @var int
     */
    private const MAX_PARSE_FAILURES = 3;

    /**
     * Constructor for MailReplyHandler.
     *
     * @param ITimeFactory       $time          The time factory
     * @param ContainerInterface $container     The DI container
     * @param IAppConfig         $appConfig     The app config interface
     * @param VotingService      $votingService The voting service
     * @param LoggerInterface    $logger        The logger
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private VotingService $votingService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 5 minutes.
        $this->setInterval(interval: 300);

    }//end __construct()

    /**
     * Execute the background job.
     *
     * Checks if email voting is enabled, fetches open VotingRounds,
     * and processes any pending email replies.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        $emailVotingEnabled = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_voting_enabled',
            'false'
        );

        if ($emailVotingEnabled !== 'true') {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Find open VotingRounds (openedAt set, closedAt null).
            $openRounds = $objectService->findObjects(
                register: 'decidesk',
                schema: 'voting-round',
                filters: ['closedAt' => null],
            );

            foreach ($openRounds as $round) {
                $this->processRoundReplies(round: $round, objectService: $objectService);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MailReplyHandler failed',
                ['exception' => $e->getMessage()]
            );
        }

    }//end run()

    /**
     * Process email replies for a single open VotingRound.
     *
     * @param array<string,mixed> $round         The VotingRound object
     * @param object              $objectService The ObjectService instance
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return void
     */
    private function processRoundReplies(array $round, object $objectService): void
    {
        $roundId   = ($round['id'] ?? '');
        $mailMeta  = ($round['_mail'] ?? []);

        if (empty($mailMeta) === true) {
            return;
        }

        foreach ($mailMeta as $replyEntry) {
            $participantId = ($replyEntry['participantId'] ?? null);
            $replyBody     = ($replyEntry['body'] ?? '');
            $failureCount  = (int) ($replyEntry['failureCount'] ?? 0);

            if ($participantId === null || $replyBody === '') {
                continue;
            }

            if ($failureCount >= self::MAX_PARSE_FAILURES) {
                // Email voting exhausted for this participant.
                continue;
            }

            $voteValue = $this->parseVoteKeyword(text: $replyBody);

            if ($voteValue !== null) {
                try {
                    $this->votingService->castVote(
                        votingRoundId: $roundId,
                        participantId: $participantId,
                        value: $voteValue,
                        isProxy: false,
                        delegatorId: null,
                    );

                    $this->sendConfirmationEmail(participantId: $participantId, voteValue: $voteValue);
                    $this->logger->info(
                        "Decidesk: Email vote '{$voteValue}' registered for participant {$participantId} in round {$roundId}"
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Decidesk: Email vote castVote failed',
                        ['exception' => $e->getMessage(), 'participantId' => $participantId]
                    );
                }
            } else {
                // Unrecognised reply — send re-prompt.
                $this->sendRepromptEmail(participantId: $participantId, roundId: $roundId);
                $this->logger->info(
                    "Decidesk: Unrecognised email vote reply from {$participantId}, sent re-prompt"
                );
            }//end if
        }//end foreach

    }//end processRoundReplies()

    /**
     * Parse the first non-empty line of an email reply for a vote keyword.
     *
     * @param string $text The email reply body
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return string|null The vote value ('for'/'against'/'abstain') or null if unrecognised
     */
    private function parseVoteKeyword(string $text): ?string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $trimmed = strtolower(trim($line));
            if ($trimmed === '') {
                continue;
            }

            if (isset(self::VOTE_KEYWORDS[$trimmed]) === true) {
                return self::VOTE_KEYWORDS[$trimmed];
            }

            // First non-empty line did not match — stop (spec: only read first non-empty line).
            break;
        }

        return null;

    }//end parseVoteKeyword()

    /**
     * Send a vote confirmation email to a participant.
     *
     * @param string $participantId The participant's Nextcloud user ID
     * @param string $voteValue     The registered vote value
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return void
     */
    private function sendConfirmationEmail(string $participantId, string $voteValue): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_confirmed',
                message: $voteValue,
                objectType: 'vote',
                objectId: '',
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send vote confirmation',
                ['exception' => $e->getMessage()]
            );
        }

    }//end sendConfirmationEmail()

    /**
     * Send a re-prompt email to a participant whose reply was unrecognised.
     *
     * @param string $participantId The participant's Nextcloud user ID
     * @param string $roundId       The VotingRound UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return void
     */
    private function sendRepromptEmail(string $participantId, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_reprompt',
                message: $roundId,
                objectType: 'voting-round',
                objectId: $roundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send re-prompt notification',
                ['exception' => $e->getMessage()]
            );
        }

    }//end sendRepromptEmail()

}//end class
