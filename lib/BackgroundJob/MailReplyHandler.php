<?php

/**
 * Decidesk Mail Reply Handler
 *
 * Background job that polls for email vote replies and registers them via VotingService.
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
 * Background job that polls open VotingRound email threads for vote replies.
 *
 * When a VotingRound is opened with remote participants, VotingService attaches
 * a `_mail` metadata entry on the round. This job polls for replies to those
 * threads, parses the first non-empty line for a vote keyword, and calls
 * VotingService::castVote() on a match.
 *
 * Vote keywords (case-insensitive): "Voor", "Tegen", "Onthouding".
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class MailReplyHandler extends TimedJob
{

    /**
     * Recognised vote keywords mapped to Vote values.
     *
     * @var array<string, string>
     */
    private const VOTE_KEYWORDS = [
        'voor'       => 'for',
        'tegen'      => 'against',
        'onthouding' => 'abstain',
    ];

    /**
     * Maximum number of unrecognised replies before abandoning email voting.
     *
     * @var int
     */
    private const MAX_FAILURES = 3;

    /**
     * Constructor for MailReplyHandler.
     *
     * @param ITimeFactory       $time          The time factory
     * @param VotingService      $votingService The voting service
     * @param IAppConfig         $appConfig     The app config
     * @param ContainerInterface $container     The DI container
     * @param LoggerInterface    $logger        The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function __construct(
        ITimeFactory $time,
        private VotingService $votingService,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run every 5 minutes (300 seconds).
        $this->setInterval(interval: 300);
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Checks whether email voting is enabled, then processes open VotingRounds
     * that have a _mail metadata entry.
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
            'false'
        );

        if ($emailVotingEnabled !== 'true') {
            return;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        // Find all open voting rounds that have email voting metadata.
        $openRounds = $objectService->findAll(
            register: 'decidesk',
            schema: 'voting-round',
            filters: ['status' => 'open']
        );

        foreach (($openRounds ?? []) as $round) {
            $roundId  = ($round['id'] ?? $round['uuid'] ?? null);
            $mailMeta = ($round['_mail'] ?? null);

            if ($roundId === null || $mailMeta === null) {
                continue;
            }

            $this->processRoundEmails(roundId: $roundId, mailMeta: $mailMeta, objectService: $objectService);
        }

    }//end run()

    /**
     * Process email replies for a single open voting round.
     *
     * @param string $roundId       The VotingRound UUID
     * @param mixed  $mailMeta      The _mail metadata (thread ID / mailbox info)
     * @param object $objectService The ObjectService instance
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function processRoundEmails(string $roundId, mixed $mailMeta, object $objectService): void
    {
        // Attempt to retrieve email replies via the Nextcloud Mail app API.
        try {
            $mailService = $this->container->get('OCA\Mail\Service\MailManager');
        } catch (\Throwable) {
            // Nextcloud Mail not available.
            return;
        }

        // The _mail metadata must contain: { threadId, accountId }.
        if (is_array($mailMeta) === false || isset($mailMeta['threadId']) === false) {
            return;
        }

        $replies = [];
        try {
            $replies = $mailService->getThread($mailMeta['accountId'] ?? 0, $mailMeta['threadId']);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: MailReplyHandler — could not fetch thread',
                ['roundId' => $roundId, 'error' => $e->getMessage()]
            );
            return;
        }

        foreach ($replies as $reply) {
            $this->processReply(roundId: $roundId, reply: $reply, objectService: $objectService);
        }

    }//end processRoundEmails()

    /**
     * Process a single email reply.
     *
     * @param string $roundId       The VotingRound UUID
     * @param mixed  $reply         The reply message object
     * @param object $objectService The ObjectService instance
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function processReply(string $roundId, mixed $reply, object $objectService): void
    {
        if (is_array($reply) === false) {
            return;
        }

        $participantId = ($reply['fromEmail'] ?? $reply['from'] ?? null);
        $body          = (string) ($reply['body'] ?? $reply['preview'] ?? '');
        $replyId       = ($reply['id'] ?? null);

        if ($participantId === null || $body === '' || $replyId === null) {
            return;
        }

        // Check if this reply was already processed.
        $processed = $objectService->findAll(
            register: 'decidesk',
            schema: 'vote',
            filters: ['emailReplyId' => $replyId]
        );

        if (empty($processed) === false) {
            return;
        }

        // Parse the first non-empty line for a vote keyword.
        $lines     = preg_split('/\r?\n/', trim($body));
        $voteValue = null;
        foreach (($lines ?? []) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $lower = strtolower($line);
            foreach (self::VOTE_KEYWORDS as $keyword => $value) {
                if (str_starts_with($lower, $keyword) === true) {
                    $voteValue = $value;
                    break 2;
                }
            }

            break;
            // Only check first non-empty line.
        }

        if ($voteValue !== null) {
            try {
                $this->votingService->castVote(
                    votingRoundId: $roundId,
                    participantId: $participantId,
                    value: $voteValue,
                    isProxy: false,
                    delegatorId: null,
                );

                // Send confirmation.
                $this->sendConfirmation(participantId: $participantId, voteValue: $voteValue, roundId: $roundId);

                $this->logger->info(
                    'Decidesk: email vote registered',
                    ['roundId' => $roundId, 'participant' => $participantId, 'value' => $voteValue]
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: email vote failed',
                    ['roundId' => $roundId, 'participant' => $participantId, 'error' => $e->getMessage()]
                );
            }//end try

            return;
        }//end if

        // Unrecognised reply: track failure count.
        $this->handleUnrecognisedReply(roundId: $roundId, participantId: $participantId, objectService: $objectService);

    }//end processReply()

    /**
     * Handle an unrecognised email reply.
     *
     * Sends a re-prompt or abandons email voting after MAX_FAILURES attempts.
     *
     * @param string $roundId       The VotingRound UUID
     * @param string $participantId The email sender
     * @param object $objectService The ObjectService instance
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function handleUnrecognisedReply(string $roundId, string $participantId, object $objectService): void
    {
        $key          = "email_vote_failures_{$roundId}_{$participantId}";
        $failureCount = (int) ($this->container->get('OCP\ICache')?->get($key) ?? 0);
        $failureCount++;

        if ($failureCount >= self::MAX_FAILURES) {
            $this->sendFinalFallback(participantId: $participantId, roundId: $roundId);
            $this->logger->info(
                'Decidesk: email voting abandoned after max failures',
                ['roundId' => $roundId, 'participant' => $participantId]
            );
            return;
        }

        try {
            $this->container->get('OCP\ICache')?->set($key, $failureCount, 3600);
        } catch (\Throwable) {
            // Cache not available.
        }

        $this->sendRePrompt(participantId: $participantId, roundId: $roundId);

    }//end handleUnrecognisedReply()

    /**
     * Send a vote confirmation email to the participant.
     *
     * @param string $participantId The participant identifier
     * @param string $voteValue     The recognised vote value
     * @param string $roundId       The VotingRound UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function sendConfirmation(string $participantId, string $voteValue, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_confirmed',
                message: "Uw stem ({$voteValue}) is geregistreerd voor stemronde {$roundId}.",
                objectType: 'voting-round',
                objectId: $roundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send vote confirmation',
                ['participant' => $participantId, 'error' => $e->getMessage()]
            );
        }

    }//end sendConfirmation()

    /**
     * Send a re-prompt email to the participant.
     *
     * @param string $participantId The participant identifier
     * @param string $roundId       The VotingRound UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function sendRePrompt(string $participantId, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_reprompt',
                message: "Uw antwoord is niet herkend. Antwoord met 'Voor', 'Tegen' of 'Onthouding'.",
                objectType: 'voting-round',
                objectId: $roundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send re-prompt',
                ['participant' => $participantId, 'error' => $e->getMessage()]
            );
        }

    }//end sendRePrompt()

    /**
     * Send a final fallback notification when email voting is exhausted.
     *
     * @param string $participantId The participant identifier
     * @param string $roundId       The VotingRound UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function sendFinalFallback(string $participantId, string $roundId): void
    {
        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: 'email_vote_exhausted',
                message: 'Stemmen per e-mail niet gelukt na 3 pogingen. Stem via de applicatie.',
                objectType: 'voting-round',
                objectId: $roundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send fallback notification',
                ['participant' => $participantId, 'error' => $e->getMessage()]
            );
        }

    }//end sendFinalFallback()
}//end class
