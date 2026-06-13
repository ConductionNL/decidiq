<?php

/**
 * Decidesk Voting Deadline Reminder Service
 *
 * Finds open voting rounds whose deadline falls within the next 24 hours
 * and notifies participants who have not voted yet
 * (nextcloud-integration spec, notification requirement).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * 24-hour pre-deadline voting reminders.
 *
 * Selection contract: a round needs a reminder when it is open (no
 * closedAt), carries a votingDeadline within the next REMINDER_WINDOW
 * seconds (and not already passed), and has no deadlineReminderSentAt
 * marker. After sending, the marker is stamped via saveObject so the
 * hourly job never reminds twice.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingDeadlineReminderService
{

    /**
     * Reminder window before the deadline, in seconds (24 hours).
     *
     * @var int
     */
    public const REMINDER_WINDOW = 86400;

    /**
     * Constructor for VotingDeadlineReminderService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OpenRegister services)
     * @param LoggerInterface    $logger    The logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Pure window check: is the deadline within (0, REMINDER_WINDOW] of now?
     *
     * @param string $deadline ISO-8601 deadline timestamp
     * @param int    $now      Current unix timestamp
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return bool True when the deadline is upcoming within the window
     */
    public function isWithinReminderWindow(string $deadline, int $now): bool
    {
        if ($deadline === '') {
            return false;
        }

        try {
            $ts = (new \DateTimeImmutable($deadline))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }

        $delta = ($ts - $now);
        return ($delta > 0 && $delta <= self::REMINDER_WINDOW);

    }//end isWithinReminderWindow()

    /**
     * Find open voting rounds that need a deadline reminder now.
     *
     * @param int $now Current unix timestamp
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return array<int, array<string, mixed>> Round payloads needing a reminder
     */
    public function findRoundsNeedingReminder(int $now): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'voting-round',
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to scan voting rounds for deadline reminders',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $due = [];
        foreach ($rows as $entity) {
            $row = $entity;
            if (is_object($entity) === true) {
                $row = (array) $entity->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            $isOpen      = (($row['closedAt'] ?? '') === '' || ($row['closedAt'] ?? null) === null);
            $alreadySent = (($row['deadlineReminderSentAt'] ?? '') !== '' && ($row['deadlineReminderSentAt'] ?? null) !== null);
            $deadline    = (string) ($row['votingDeadline'] ?? '');

            if ($isOpen === true && $alreadySent === false
                && $this->isWithinReminderWindow(deadline: $deadline, now: $now) === true
            ) {
                $due[] = $row;
            }
        }

        return $due;

    }//end findRoundsNeedingReminder()

    /**
     * Send the reminder for one round and stamp the sent marker.
     *
     * Audience: meeting participants who have not cast a vote in this
     * round yet (motion → meeting → participants walk; participants
     * without a nextcloudUserId link are skipped).
     *
     * @param array<string, mixed> $round Round payload (from findRoundsNeedingReminder)
     * @param int                  $now   Current unix timestamp (marker value)
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return int Number of reminder notifications sent
     */
    public function remindRound(array $round, int $now): int
    {
        $roundId = (string) ($round['id'] ?? ($round['@self']['id'] ?? ''));
        if ($roundId === '') {
            return 0;
        }

        try {
            $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $notificationService = $this->container->get('OpenRegisterNotificationService');

            $votedUserIds = $this->resolveVotedUserIds(objectService: $objectService, roundId: $roundId);
            $audience     = $this->resolveParticipantUserIds(objectService: $objectService, round: $round);

            $pending = array_values(array_diff($audience, $votedUserIds));

            $sent = 0;
            foreach ($pending as $uid) {
                try {
                    $notificationService->sendNotification(
                        userId: $uid,
                        title: 'Voting deadline approaching',
                        message: 'A voting round closes within 24 hours and your vote has not been cast yet.',
                        deepLink: '/voting-rounds/'.$roundId
                    );
                    $sent++;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Decidesk: deadline reminder notification failed',
                        ['roundId' => $roundId, 'uid' => $uid, 'exception' => $e->getMessage()]
                    );
                }
            }

            // Stamp the marker even when the audience was empty — the round was
            // evaluated and must not be re-scanned every hour until the deadline.
            $round['deadlineReminderSentAt'] = gmdate('Y-m-d\TH:i:s\Z', $now);
            $objectService->saveObject(
                object: $round,
                register: 'decidesk',
                schema: 'voting-round',
                uuid: $roundId,
            );

            $this->logger->info(
                'Decidesk: voting deadline reminders sent',
                ['roundId' => $roundId, 'sent' => $sent, 'pending' => count($pending)]
            );

            return $sent;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: deadline reminder failed for round',
                ['roundId' => $roundId, 'exception' => $e->getMessage()]
            );
            return 0;
        }//end try

    }//end remindRound()

    /**
     * Run a full reminder sweep (called by the hourly background job).
     *
     * @param int $now Current unix timestamp
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return int Total notifications sent across all due rounds
     */
    public function run(int $now): int
    {
        $total = 0;
        foreach ($this->findRoundsNeedingReminder(now: $now) as $round) {
            $total += $this->remindRound(round: $round, now: $now);
        }

        return $total;

    }//end run()

    /**
     * Nextcloud UIDs of participants who already voted in this round.
     *
     * @param object $objectService OpenRegister ObjectService instance
     * @param string $roundId       UUID of the voting round
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string[]
     */
    private function resolveVotedUserIds(object $objectService, string $roundId): array
    {
        try {
            $votes = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'vote',
                    'filters'  => ['votingRound' => $roundId],
                ]
            );
        } catch (\Throwable) {
            return [];
        }

        $uids = [];
        foreach ($votes as $entity) {
            $row = $entity;
            if (is_object($entity) === true) {
                $row = (array) $entity->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            $casterId = ($row['caster'] ?? null);
            if (is_array($casterId) === true) {
                $casterId = ($casterId['id'] ?? null);
            }

            if (is_string($casterId) === true && $casterId !== '') {
                $uid = $this->participantUserId(objectService: $objectService, participantId: $casterId);
                if ($uid !== null) {
                    $uids[] = $uid;
                }
            }
        }//end foreach

        return array_values(array_unique($uids));

    }//end resolveVotedUserIds()

    /**
     * Nextcloud UIDs of the meeting participants behind this round
     * (round → motion → meeting → participants).
     *
     * @param object               $objectService OpenRegister ObjectService instance
     * @param array<string, mixed> $round         Round payload
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string[]
     */
    private function resolveParticipantUserIds(object $objectService, array $round): array
    {
        $motionId = ($round['motion'] ?? null);
        if (is_array($motionId) === true) {
            $motionId = ($motionId['id'] ?? null);
        }

        if (is_string($motionId) === false || $motionId === '') {
            return [];
        }

        try {
            $motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
        } catch (\Throwable) {
            return [];
        }

        if ($motionEntity === null) {
            return [];
        }

        $motion    = (array) $motionEntity->jsonSerialize();
        $meetingId = ($motion['meeting'] ?? ($motion['relations']['Meeting'][0] ?? null));
        if (is_array($meetingId) === true) {
            $meetingId = ($meetingId['id'] ?? null);
        }

        if (is_string($meetingId) === false || $meetingId === '') {
            return [];
        }

        try {
            $participants = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'participant',
                    'filters'  => ['meeting' => $meetingId],
                ]
            );
        } catch (\Throwable) {
            return [];
        }

        $uids = [];
        foreach ($participants as $entity) {
            $row = $entity;
            if (is_object($entity) === true) {
                $row = (array) $entity->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            $uid = ($row['nextcloudUserId'] ?? ($row['owner'] ?? null));
            if (is_string($uid) === true && $uid !== '') {
                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids));

    }//end resolveParticipantUserIds()

    /**
     * Resolve a participant UUID to its linked Nextcloud UID.
     *
     * @param object $objectService OpenRegister ObjectService instance
     * @param string $participantId UUID of the participant
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string|null
     */
    private function participantUserId(object $objectService, string $participantId): ?string
    {
        try {
            $entity = $objectService->find(id: $participantId, register: 'decidesk', schema: 'participant');
        } catch (\Throwable) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        $row = (array) $entity->jsonSerialize();
        $uid = ($row['nextcloudUserId'] ?? ($row['owner'] ?? null));
        if (is_string($uid) === true && $uid !== '') {
            return $uid;
        }

        return null;

    }//end participantUserId()
}//end class
