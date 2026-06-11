<?php
/**
 * Decidesk Mail Reply Handler Background Job
 *
 * Polls for email replies to voting notification threads and casts votes
 * based on the first non-empty line of the reply body.
 *
 * Vote-by-email is an ADR-022 statutory-voting exception: the vote-casting
 * logic stays in the in-app voting path (VotingService::castVote) and is NOT
 * migrated to a leaf (migrate-email-links-to-email-leaf, design D2). Thread
 * association never used the retired in-app EmailLink store — it is held by
 * HMAC-signed `_mail` metadata on the VotingRound OR object (the registry's
 * own object), so retiring EmailLinkService does not affect this handler.
 * The vote thread surfaces through the Email integration leaf bound to the
 * motion/decision object via the registry, not an EmailLink object.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
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
     * HMAC algorithm used for _mail entry signatures.
     *
     * @var string
     */
    private const HMAC_ALGO = 'sha256';

    /**
     * Derive the per-round HMAC secret from the app's voter_token_secret.
     *
     * Uses the same underlying voter_token_secret as VotingService so that
     * both services share one secret without tight coupling. A domain prefix
     * differentiates this use-case from vote-token HMACs.
     *
     * @param string $roundId The VotingRound UUID
     *
     * @return string The derived per-round secret (hex)
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function mailHmacSecret(string $roundId): string
    {
        $base = $this->appConfig->getValueString(Application::APP_ID, 'voter_token_secret', '');
        if ($base === '') {
            // Generate and persist a base secret if one does not yet exist.
            $base = bin2hex(random_bytes(32));
            $this->appConfig->setValueString(Application::APP_ID, 'voter_token_secret', $base);
        }

        // Derive a round-scoped key using HKDF-style domain separation.
        return hash_hmac(self::HMAC_ALGO, 'mail-reply:'.$roundId, $base);

    }//end mailHmacSecret()

    /**
     * Compute the HMAC for a _mail entry.
     *
     * The signed payload is the canonical concatenation:
     *   participantId + ':' + roundId + ':' + timestamp
     * Covers the three fields that identify a unique, time-bound vote instruction.
     * `replyBody` is intentionally excluded from the signed payload so that the
     * ingestion path does not need to know the vote value at signing time (the vote
     * body is trusted only after the signature is verified).
     *
     * @param string $participantId The participant UUID
     * @param string $roundId       The VotingRound UUID
     * @param string $timestamp     ISO 8601 timestamp written by the ingestion path
     *
     * @return string The hex HMAC
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function computeMailHmac(string $participantId, string $roundId, string $timestamp): string
    {
        $payload = $participantId.':'.$roundId.':'.$timestamp;
        return hash_hmac(self::HMAC_ALGO, $payload, $this->mailHmacSecret(roundId: $roundId));

    }//end computeMailHmac()

    /**
     * Sign a _mail entry array and return it with the hmac field set.
     *
     * The ingestion path (SMTP webhook, future API) MUST call this method before
     * writing a _mail entry to the VotingRound object. Any entry lacking a valid
     * hmac field is rejected by processRoundMailReplies() before counting.
     *
     * @param array<string,mixed> $entry   The raw _mail entry (must contain participantId and timestamp)
     * @param string              $roundId The VotingRound UUID (used to derive the secret)
     *
     * @return array<string,mixed> The entry with hmac appended
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    public function signMailEntry(array $entry, string $roundId): array
    {
        $participantId = (string) ($entry['participantId'] ?? '');
        $timestamp     = (string) ($entry['timestamp'] ?? '');

        $entry['hmac'] = $this->computeMailHmac(
            participantId: $participantId,
            roundId: $roundId,
            timestamp: $timestamp
        );

        return $entry;

    }//end signMailEntry()

    /**
     * Verify the HMAC on a _mail entry.
     *
     * Returns false for any entry that is missing the hmac field, has an
     * empty participantId, or whose HMAC does not match the expected value.
     *
     * @param array<string,mixed> $entry   The _mail entry to verify
     * @param string              $roundId The VotingRound UUID
     *
     * @return bool True when the entry is authentically signed
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
     */
    private function verifyMailHmac(array $entry, string $roundId): bool
    {
        $providedHmac  = (string) ($entry['hmac'] ?? '');
        $participantId = (string) ($entry['participantId'] ?? '');
        $timestamp     = (string) ($entry['timestamp'] ?? '');

        if ($providedHmac === '' || $participantId === '' || $timestamp === '') {
            return false;
        }

        $expectedHmac = $this->computeMailHmac(
            participantId: $participantId,
            roundId: $roundId,
            timestamp: $timestamp
        );

        // Constant-time comparison prevents timing-oracle attacks.
        return hash_equals($expectedHmac, $providedHmac);

    }//end verifyMailHmac()

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

        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $roundEntities = $objectService->findAll(['filters' => ['closedAt' => null, 'openedAt' => ['!=' => null]]]);

        foreach ($roundEntities as $roundEntity) {
            $round   = $roundEntity->jsonSerialize();
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

            // Reject any _mail entry that lacks a valid HMAC signature.
            // An entry without a signature was not written by the trusted ingestion path —
            // it may have been injected directly into OpenRegister by a user with write
            // access to the VotingRound object (OWASP A08:2021 / issue #299).
            if ($this->verifyMailHmac(entry: $mailEntry, roundId: $roundId) === false) {
                $this->logger->warning(
                    'Decidesk: MailReplyHandler — _mail entry rejected: missing or invalid HMAC signature',
                    [
                        'participantId' => $participantId,
                        'votingRoundId' => $roundId,
                    ]
                );
                continue;
            }

            // Validate that the participantId from _mail metadata refers to an existing Participant
            // object before casting any vote. This prevents manipulated metadata from casting
            // votes on behalf of arbitrary or non-existent participants (OWASP A07:2021).
            $participantEntity = $objectService->find(id: $participantId, register: 'decidesk', schema: 'participant');
            $participant       = null;
            if ($participantEntity !== null) {
                $participant = $participantEntity->jsonSerialize();
            }

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

            // Resolve Nextcloud UID for notifications — participant UUID is not a valid Nextcloud userId.
            // Prefer the stored nextcloudUserId; fall back to email lookup via IUserManager.
            $notifyUid = $participant['nextcloudUserId'] ?? null;
            if ($notifyUid === null) {
                $email = $participant['email'] ?? null;
                if ($email !== null) {
                    try {
                        $userManager = $this->container->get(\OCP\IUserManager::class);
                        $users       = $userManager->getByEmail($email);
                        if (count($users) === 1) {
                            $notifyUid = $users[0]->getUID();
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Decidesk: could not resolve Nextcloud UID for participant', ['participantId' => $participantId]);
                    }
                }
            }

            // Refuse email votes on secret rounds: email does not provide ballot secrecy.
            // An email reply is visible in transit logs and to the mail server operator;
            // counting it as a secret ballot would undermine the anonymity guarantee
            // (issue #299, item 4 of suggested fix).
            if ((bool) ($round['isSecret'] ?? false) === true) {
                $this->logger->warning(
                    'Decidesk: MailReplyHandler — email vote rejected: round is a secret ballot',
                    [
                        'participantId' => $participantId,
                        'votingRoundId' => $roundId,
                    ]
                );
                $mailEntry['processed']    = true;
                $mailEntry['abandoned']    = true;
                $mailEntry['rejectReason'] = 'secret-ballot';
                $dirty = true;
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

                    // Send confirmation — use Nextcloud UID, not OpenRegister participant UUID.
                    if ($notifyUid !== null) {
                        $notificationService->createNotification(
                            userId: $notifyUid,
                            app: 'decidesk',
                            subject: 'email_vote_confirmed',
                            subjectParameters: ['value' => $keyword, 'votingRoundId' => $roundId],
                            object: 'voting-round',
                            objectId: $roundId
                        );
                    }

                    $mailEntry['processed'] = true;
                    $dirty = true;
                    $this->logger->info('Decidesk: email vote processed', ['participant' => $participantId]);
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
                    if ($notifyUid !== null) {
                        try {
                            $notificationService->createNotification(
                                userId: $notifyUid,
                                app: 'decidesk',
                                subject: 'email_vote_abandoned',
                                subjectParameters: ['votingRoundId' => $roundId],
                                object: 'voting-round',
                                objectId: $roundId
                            );
                        } catch (\Throwable $e) {
                            $this->logger->warning('Decidesk: abandoned vote notification failed', ['error' => $e->getMessage()]);
                        }
                    }
                } else {
                    $mailEntry['retries'] = $retries;
                    $dirty = true;
                    if ($notifyUid !== null) {
                        try {
                            $notificationService->createNotification(
                                userId: $notifyUid,
                                app: 'decidesk',
                                subject: 'email_vote_reprompt',
                                subjectParameters: ['votingRoundId' => $roundId, 'attempt' => $retries],
                                object: 'voting-round',
                                objectId: $roundId
                            );
                        } catch (\Throwable $e) {
                            $this->logger->warning('Decidesk: reprompt notification failed', ['error' => $e->getMessage()]);
                        }
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
