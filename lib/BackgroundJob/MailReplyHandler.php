<?php

/**
 * Decidesk Mail Reply Handler Background Job
 *
 * Polls for email replies to voting invitation threads and registers votes via
 * VotingService when a recognised vote keyword is found in the reply body.
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
 * Background job that polls for email vote replies in open VotingRounds.
 *
 * Recognised vote keywords (case-insensitive, first non-empty line):
 * - "Voor"       → "for"
 * - "Tegen"      → "against"
 * - "Onthouding" → "abstain"
 *
 * On unrecognised reply: sends re-prompt notification. After 3 failures per
 * round per Participant, email voting is marked exhausted for that combination.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailReplyHandler extends TimedJob
{

    /**
     * Maximum failed attempts before fallback to UI voting.
     *
     * @var int
     */
    private const MAX_FAILURES = 3;

    /**
     * Map of Dutch vote keywords (lower-case) to vote values.
     *
     * @var array<string,string>
     */
    private const VOTE_KEYWORDS = [
        'voor'       => 'for',
        'tegen'      => 'against',
        'onthouding' => 'abstain',
    ];

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time          Nextcloud time factory
     * @param VotingService      $votingService Voting service
     * @param IAppConfig         $appConfig     App config for feature flag
     * @param ContainerInterface $container     DI container
     * @param LoggerInterface    $logger        PSR logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function __construct(
        ITimeFactory $time,
        private VotingService $votingService,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run every 5 minutes.
        $this->setInterval(seconds: 5 * 60);
    }//end __construct()

    /**
     * Execute the background job: poll for email replies and register votes.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    protected function run(mixed $argument): void
    {
        // Respect the "email voting" feature flag.
        $emailVotingEnabled = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_voting_enabled',
            'false'
        );

        if ($emailVotingEnabled !== 'true') {
            return;
        }

        try {
            $this->processEmailReplies();
        } catch (\Throwable $e) {
            $this->logger->error("MailReplyHandler: unexpected error: {$e->getMessage()}");
        }
    }//end run()

    /**
     * Process all pending email replies for open VotingRounds.
     *
     * Fetches open VotingRounds that have `_mail` metadata, then checks for
     * unprocessed reply messages.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function processEmailReplies(): void
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        // Find open VotingRounds (openedAt set, closedAt null).
        $openRounds = $objectService->findObjects(
            register: 'decidesk',
            schema: 'voting-round',
            filters: ['closedAt' => null]
        ) ?? [];

        foreach ($openRounds as $round) {
            $roundId = $round['id'] ?? $round['uuid'] ?? null;
            if ($roundId === null) {
                continue;
            }

            // Only process rounds that have email metadata.
            $mailMeta = null;
            foreach (($round['notes'] ?? []) as $note) {
                if (($note['title'] ?? '') === '_mail') {
                    $mailMeta = json_decode($note['body'] ?? '{}', true);
                    break;
                }
            }

            if ($mailMeta === null) {
                continue;
            }

            $this->processRoundEmailReplies(round: $round, roundId: $roundId, mailMeta: $mailMeta);
        }//end foreach
    }//end processEmailReplies()

    /**
     * Process email replies for a single VotingRound.
     *
     * @param array<string,mixed> $round    The VotingRound object
     * @param string              $roundId  UUID of the VotingRound
     * @param array<string,mixed> $mailMeta Email metadata from the round's _mail note
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function processRoundEmailReplies(array $round, string $roundId, array $mailMeta): void
    {
        $replies = $mailMeta['pendingReplies'] ?? [];

        foreach ($replies as $reply) {
            $participantId = $reply['participantId'] ?? null;
            $replyBody     = $reply['body'] ?? '';
            $replyId       = $reply['id'] ?? null;

            if ($participantId === null) {
                continue;
            }

            $voteValue = $this->parseVoteKeyword(body: $replyBody);

            if ($voteValue !== null) {
                try {
                    $this->votingService->castVote(
                        votingRoundId: $roundId,
                        participantId: $participantId,
                        value: $voteValue,
                        isProxy: false
                    );

                    $this->sendConfirmationEmail(participantId: $participantId, roundId: $roundId, voteValue: $voteValue);
                    $this->markReplyProcessed(round: $round, roundId: $roundId, replyId: $replyId);

                    $this->logger->info("Email vote registered: participant={$participantId}, value={$voteValue}, round={$roundId}");
                } catch (\Throwable $e) {
                    $this->logger->warning("MailReplyHandler: castVote failed for {$participantId}: {$e->getMessage()}");
                }
            } else {
                $this->handleUnrecognisedReply(round: $round, roundId: $roundId, participantId: $participantId, replyId: $replyId);
            }
        }//end foreach
    }//end processRoundEmailReplies()

    /**
     * Parse the first non-empty line of an email reply body for a vote keyword.
     *
     * @param string $body The email reply body text
     *
     * @return string|null The normalised vote value ("for"|"against"|"abstain") or null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function parseVoteKeyword(string $body): ?string
    {
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) === false) {
                $lower = strtolower($trimmed);
                if (array_key_exists($lower, self::VOTE_KEYWORDS) === true) {
                    return self::VOTE_KEYWORDS[$lower];
                }

                // No match on first non-empty line — stop.
                return null;
            }
        }

        return null;
    }//end parseVoteKeyword()

    /**
     * Handle an unrecognised email reply: send re-prompt or exhaust email voting.
     *
     * @param array<string,mixed> $round         VotingRound object
     * @param string              $roundId       UUID of the VotingRound
     * @param string              $participantId UUID of the Participant
     * @param string|null         $replyId       ID of the reply being processed
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function handleUnrecognisedReply(array $round, string $roundId, string $participantId, ?string $replyId): void
    {
        // Count existing failures for this participant/round combo.
        $failures = 0;
        foreach (($round['notes'] ?? []) as $note) {
            if (($note['title'] ?? '') === "email_fail:{$participantId}") {
                $failures++;
            }
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        if ($failures >= self::MAX_FAILURES) {
            // Exhaust email voting for this participant.
            try {
                $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
                $notificationService->sendNotification(
                    userId: $participantId,
                    subject: 'E-mailstemmen niet gelukt',
                    message: 'Uw e-mailstem kon niet worden verwerkt. Stem via de Decidesk applicatie.',
                    link: "/apps/decidesk"
                );
            } catch (\Throwable $e) {
                $this->logger->warning("MailReplyHandler: notification failed: {$e->getMessage()}");
            }

            $this->markReplyProcessed(round: $round, roundId: $roundId, replyId: $replyId);
        } else {
            // Increment failure count and send re-prompt.
            $round['notes'][] = [
                'title' => "email_fail:{$participantId}",
                'body'  => json_encode(['replyId' => $replyId, 'at' => date(DATE_ATOM)]),
            ];
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

            try {
                $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
                $notificationService->sendNotification(
                    userId: $participantId,
                    subject: 'Stem niet herkend',
                    message: "Uw antwoord kon niet worden verwerkt. Antwoord met één van: Voor, Tegen, Onthouding.",
                    link: "/apps/decidesk"
                );
            } catch (\Throwable $e) {
                $this->logger->warning("MailReplyHandler: re-prompt notification failed: {$e->getMessage()}");
            }
        }//end if
    }//end handleUnrecognisedReply()

    /**
     * Mark a reply as processed by removing it from the pending replies list.
     *
     * @param array<string,mixed> $round   VotingRound object
     * @param string              $roundId UUID of the VotingRound
     * @param string|null         $replyId ID of the processed reply
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function markReplyProcessed(array $round, string $roundId, ?string $replyId): void
    {
        if ($replyId === null) {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            foreach ($round['notes'] as &$note) {
                if (($note['title'] ?? '') === '_mail') {
                    $mailMeta = json_decode($note['body'] ?? '{}', true);
                    $mailMeta['pendingReplies'] = array_values(
                        array_filter(
                            $mailMeta['pendingReplies'] ?? [],
                            fn($r) => ($r['id'] ?? null) !== $replyId
                        )
                    );
                    $note['body'] = json_encode($mailMeta);
                    break;
                }
            }

            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        } catch (\Throwable $e) {
            $this->logger->warning("MailReplyHandler: markReplyProcessed failed: {$e->getMessage()}");
        }//end try
    }//end markReplyProcessed()

    /**
     * Send a confirmation email to a Participant after their vote is registered.
     *
     * @param string $participantId UUID of the Participant
     * @param string $roundId       UUID of the VotingRound
     * @param string $voteValue     The registered vote value
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function sendConfirmationEmail(string $participantId, string $roundId, string $voteValue): void
    {
        $labels = [
            'for'     => 'Voor',
            'against' => 'Tegen',
            'abstain' => 'Onthouding',
        ];

        $label = $labels[$voteValue] ?? $voteValue;

        try {
            $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
            $notificationService->sendNotification(
                userId: $participantId,
                subject: "Stem bevestigd: {$label}",
                message: "Uw stem '{$label}' is geregistreerd voor stemronde {$roundId}.",
                link: "/apps/decidesk"
            );
        } catch (\Throwable $e) {
            $this->logger->warning("MailReplyHandler: confirmation notification failed: {$e->getMessage()}");
        }
    }//end sendConfirmationEmail()
}//end class
