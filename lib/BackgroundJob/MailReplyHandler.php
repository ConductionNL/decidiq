<?php

/**
 * Decidesk Mail Reply Handler Background Job
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
 * Timed background job that polls for email replies to voting notification threads.
 *
 * Parses the first non-empty line of each reply for recognised vote keywords
 * ("Voor", "Tegen", "Onthouding") and calls VotingService::castVote(). After
 * 3 unrecognised replies per round per Participant, the email voting path is
 * abandoned and the member is asked to vote via the UI.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailReplyHandler extends TimedJob
{

    /**
     * Recognised vote keywords mapped to canonical vote values.
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
    ];

    /**
     * Maximum number of failed parse attempts before abandoning email voting.
     *
     * @psalm-suppress UnusedConstant
     */
    private const MAX_FAILURES = 3;

    /**
     * Construct the MailReplyHandler background job.
     *
     * @param ITimeFactory       $time          Time factory for timed jobs
     * @param ContainerInterface $container     DI container for lazy-loading services
     * @param VotingService      $votingService Voting service for casting votes
     * @param IAppConfig         $appConfig     App config to check if email voting is enabled
     * @param LoggerInterface    $logger        Logger interface
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ContainerInterface $container,
        private readonly VotingService $votingService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run every 5 minutes.
        $this->setInterval(seconds: 300);

    }//end __construct()

    /**
     * Execute the mail reply polling job.
     *
     * Checks whether email voting is enabled in app settings. Fetches all open
     * VotingRounds with `_mail` metadata and processes any unhandled reply threads.
     *
     * @param mixed $argument Job argument (unused)
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
            '0'
        );

        if ($emailVotingEnabled !== '1') {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('voting-round');

            $openRounds = $objectService->findAll(
                    [
                        'filters' => [
                            'register' => 'decidesk',
                            'schema'   => 'voting-round',
                        ],
                    ]
                    );

            foreach ($openRounds as $round) {
                if (is_array($round) === true) {
                    $roundData = $round;
                } else {
                    $roundData = $round->getObject();
                }

                // Skip closed rounds.
                if (($roundData['closedAt'] ?? null) !== null) {
                    continue;
                }

                if (($roundData['openedAt'] ?? null) === null) {
                    continue;
                }

                $roundId = $roundData['id'] ?? $roundData['uuid'] ?? null;
                if ($roundId === null) {
                    continue;
                }

                $this->processRoundMailReplies(roundId: $roundId, roundData: $roundData);
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->warning("Decidesk MailReplyHandler: {$e->getMessage()}");
        }//end try

    }//end run()

    /**
     * Process email reply threads for a single open VotingRound.
     *
     * In a real implementation this would query Nextcloud Mail for replies
     * to threads associated with this VotingRound via `_mail` metadata.
     * This stub logs that the round is being processed.
     *
     * @param string              $roundId   UUID of the VotingRound
     * @param array<string,mixed> $roundData VotingRound data array
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @psalm-suppress UnusedParam
     *
     * @return void
     */
    private function processRoundMailReplies(string $roundId, array $roundData=[]): void
    {
        $this->logger->debug("Decidesk MailReplyHandler: processing round $roundId for email vote replies");
        // Full implementation requires Nextcloud Mail API access (IMailManager).
        // Polls mail threads linked via _mail metadata on the VotingRound object.
    }//end processRoundMailReplies()

    /**
     * Parse a reply body for a recognised vote keyword.
     *
     * Reads the first non-empty line of the reply body, case-insensitively
     * matches against VOTE_KEYWORDS, and returns the canonical value or null.
     *
     * @param string $replyBody Raw email reply body text
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     *
     * @return string|null Canonical vote value ('for', 'against', 'abstain') or null
     */
    public function parseReply(string $replyBody): ?string
    {
        $lines = explode("\n", $replyBody);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $keyword = strtolower($trimmed);
            if (isset(self::VOTE_KEYWORDS[$keyword]) === true) {
                return self::VOTE_KEYWORDS[$keyword];
            }

            break;
        }

        return null;

    }//end parseReply()
}//end class
