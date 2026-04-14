<?php

/**
 * Decidesk Mail Reply Handler Background Job
 *
 * Polls for email replies to voting notification threads and casts votes
 * based on the first non-empty line of the reply body.
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
 * Background job that polls for email vote replies on open VotingRounds.
 *
 * Parses the first non-empty line of each reply for recognised vote keywords:
 * "Voor" (for), "Tegen" (against), "Onthouding" (abstain).
 * On unrecognised reply, sends re-prompt (max 3 retries per member per round).
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailReplyHandler extends TimedJob
{

    /**
     * Recognised vote keywords mapped to canonical values.
     *
     * @var array<string, string>
     */
    private const VOTE_KEYWORDS = [
        'voor'       => 'for',
        'for'        => 'for',
        'tegen'      => 'against',
        'against'    => 'against',
        'onthouding' => 'abstain',
        'abstain'    => 'abstain',
        'abstention' => 'abstain',
    ];

    /**
     * Maximum unrecognised reply attempts before email voting is abandoned.
     *
     * @var int
     */
    private const MAX_RETRIES = 3;

    /**
     * Constructor for MailReplyHandler.
     *
     * @param ITimeFactory       $time          Nextcloud time factory
     * @param VotingService      $votingService The voting service
     * @param IAppConfig         $appConfig     The app config
     * @param ContainerInterface $container     The DI container
     * @param LoggerInterface    $logger        The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function __construct(
        ITimeFactory $time,
        private readonly VotingService $votingService,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run every 5 minutes.
        $this->setInterval(seconds: 300);

    }//end __construct()

    /**
     * Run the background job: poll email replies and process votes.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    protected function run(mixed $argument): void
    {
        $emailVotingEnabled = $this->appConfig->getValueString(Application::APP_ID, 'email_voting_enabled', '0');
        if ($emailVotingEnabled !== '1') {
            return;
        }

        try {
            $this->processOpenRounds();
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: MailReplyHandler failed', ['error' => $e->getMessage()]);
        }

    }//end run()

    /**
     * Find open VotingRounds and process their email reply metadata.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function processOpenRounds(): void
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $openRounds = $objectService->findObjects(
            register: 'decidesk',
            schema: 'voting-round',
            filters: ['closedAt' => null, 'openedAt' => ['!=' => null]]
        );

        foreach (($openRounds['results'] ?? []) as $round) {
            $roundId = ($round['uuid'] ?? $round['id'] ?? null);
            if ($roundId === null) {
                continue;
            }

            $this->processRoundMailReplies(objectService: $objectService, round: $round, roundId: $roundId);
        }

    }//end processOpenRounds()

    /**
     * Process mail reply metadata on a single VotingRound.
     *
     * Looks for _mail metadata entries, parses vote keywords, and calls castVote.
     *
     * @param object              $objectService The OpenRegister ObjectService
     * @param array<string,mixed> $round         The VotingRound object
     * @param string              $roundId       The VotingRound UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function processRoundMailReplies(object $objectService, array $round, string $roundId): void
    {
        if (empty($round['_mail'] ?? []) === true) {
            return;
        }

        $notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
        $dirty = false;

        foreach ($round['_mail'] as &$mailEntry) {
            $participantId = ($mailEntry['participantId'] ?? null);
            $replyBody     = ($mailEntry['replyBody'] ?? '');
            $processed     = (bool) ($mailEntry['processed'] ?? false);

            if ($processed === true || $participantId === null) {
                continue;
            }

            // Validate that the participantId from _mail metadata refers to an existing Participant
            // object before casting any vote. This prevents manipulated metadata from casting
            // votes on behalf of arbitrary or non-existent participants (OWASP A07:2021).
            $participant = $objectService->getObject(register: 'decidesk', schema: 'participant', uuid: $participantId);
            if ($participant === null) {
                $this->logger->warning(
                    'Decidesk: MailReplyHandler — unknown participantId in _mail metadata, skipping',
                    [
                        'participantId' => $participantId,
                        'votingRoundId' => $roundId,
                    ]
                );
                continue;
            }

            $keyword = $this->parseVoteKeyword(body: $replyBody);

            if ($keyword !== null) {
                try {
                    $this->votingService->castVote(
                        votingRoundId: $roundId,
                        participantId: $participantId,
                        value: $keyword,
                        isProxy: false,
                        delegatorId: null
                    );

                    // Send confirmation.
                    $notificationService->createNotification(
                        userId: $participantId,
                        app: 'decidesk',
                        subject: 'email_vote_confirmed',
                        subjectParameters: ['value' => $keyword, 'votingRoundId' => $roundId],
                        object: 'voting-round',
                        objectId: $roundId
                    );

                    $mailEntry['processed'] = true;
                    $dirty = true;
                    $this->logger->info('Decidesk: email vote processed', ['participant' => $participantId, 'value' => $keyword]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Decidesk: email vote cast failed', ['error' => $e->getMessage()]);
                }//end try
            } else {
                $retries = (int) ($mailEntry['retries'] ?? 0);
                $retries++;

                if ($retries >= self::MAX_RETRIES) {
                    $mailEntry['processed'] = true;
                    $mailEntry['abandoned'] = true;
                    $dirty = true;
                    try {
                        $notificationService->createNotification(
                            userId: $participantId,
                            app: 'decidesk',
                            subject: 'email_vote_abandoned',
                            subjectParameters: ['votingRoundId' => $roundId],
                            object: 'voting-round',
                            objectId: $roundId
                        );
                    } catch (\Throwable $e) {
                        $this->logger->warning('Decidesk: abandoned vote notification failed', ['error' => $e->getMessage()]);
                    }
                } else {
                    $mailEntry['retries'] = $retries;
                    $dirty = true;
                    try {
                        $notificationService->createNotification(
                            userId: $participantId,
                            app: 'decidesk',
                            subject: 'email_vote_reprompt',
                            subjectParameters: ['votingRoundId' => $roundId, 'attempt' => $retries],
                            object: 'voting-round',
                            objectId: $roundId
                        );
                    } catch (\Throwable $e) {
                        $this->logger->warning('Decidesk: reprompt notification failed', ['error' => $e->getMessage()]);
                    }
                }//end if
            }//end if
        }//end foreach

        unset($mailEntry);

        // Persist mutations: write the updated _mail metadata back to OpenRegister.
        if ($dirty === true) {
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

    }//end processRoundMailReplies()

    /**
     * Parse the first non-empty line of an email reply for a vote keyword.
     *
     * Returns the canonical vote value (for/against/abstain) or null if unrecognised.
     *
     * @param string $body The email reply body
     *
     * @return string|null The canonical vote value or null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function parseVoteKeyword(string $body): ?string
    {
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $normalised = strtolower($line);
            if (isset(self::VOTE_KEYWORDS[$normalised]) === true) {
                return self::VOTE_KEYWORDS[$normalised];
            }

            // First non-empty line is not recognised.
            return null;
        }

        return null;

    }//end parseVoteKeyword()
}//end class
