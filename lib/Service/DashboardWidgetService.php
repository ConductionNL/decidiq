<?php

/**
 * Decidesk Dashboard Widget Service
 *
 * Resolves the current user's governance summary for the Nextcloud main
 * dashboard widget (OCP\Dashboard\IWidget): pending votes count and next
 * upcoming meeting. User-scoped (per-user, no IDOR) and fail-soft so a broken
 * or absent register never crashes the Nextcloud dashboard.
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
 * @spec openspec/specs/dashboard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-user data resolution for the Nextcloud main dashboard widget.
 *
 * Mirrors the v2 in-app widgets' domain logic (widgetLogic.js) and the
 * VotingDeadlineReminderService voted-user resolution: the current Nextcloud
 * user is matched to their participant record via `nextcloudUserId`; pending
 * votes are open voting-rounds that participant has not cast a vote in; the
 * next meeting is the soonest future lifecycle=scheduled meeting the user
 * participates in. A user with no participant record is not a voting member,
 * so they see zero pending votes.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DashboardWidgetService
{
    /**
     * Constructor for DashboardWidgetService.
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
     * Resolve the current user's dashboard summary.
     *
     * Fail-soft: any failure (OpenRegister absent, register missing, schema
     * drift) yields a zero/empty summary so the Nextcloud dashboard never sees
     * an exception.
     *
     * @param string $userId Current Nextcloud user id (passed by the platform)
     * @param int    $now    Current unix timestamp
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return array{pendingVotes:int, nextMeeting:array<string,mixed>|null} Summary
     */
    public function getUserSummary(string $userId, int $now): array
    {
        if ($userId === '') {
            return ['pendingVotes' => 0, 'nextMeeting' => null];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: dashboard widget could not resolve ObjectService',
                ['exception' => $e->getMessage()]
            );
            return ['pendingVotes' => 0, 'nextMeeting' => null];
        }

        $participantIds = $this->resolveParticipantIds(objectService: $objectService, userId: $userId);

        return [
            'pendingVotes' => $this->countPendingVotes(objectService: $objectService, participantIds: $participantIds),
            'nextMeeting'  => $this->resolveNextMeeting(objectService: $objectService, participantIds: $participantIds, now: $now),
        ];

    }//end getUserSummary()

    /**
     * Count open voting-rounds the current user has not voted in.
     *
     * @param object   $objectService  OpenRegister ObjectService instance
     * @param string[] $participantIds Participant record ids for the current user
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return int Number of open rounds awaiting the user's vote
     */
    public function countPendingVotes(object $objectService, array $participantIds): int
    {
        // No participant record ⇒ not a voting member ⇒ zero pending.
        if (count($participantIds) === 0) {
            return 0;
        }

        $openRoundIds = [];
        foreach ($this->fetch(objectService: $objectService, schema: 'voting-round') as $round) {
            $isOpen = (($round['closedAt'] ?? '') === '' || ($round['closedAt'] ?? null) === null);
            if ($isOpen === false) {
                continue;
            }

            $lifecycle = (string) ($round['lifecycle'] ?? 'open');
            if ($lifecycle !== '' && $lifecycle !== 'open') {
                continue;
            }

            $roundId = $this->idOf(row: $round);
            if ($roundId !== '') {
                $openRoundIds[$roundId] = true;
            }
        }

        if (count($openRoundIds) === 0) {
            return 0;
        }

        // Remove rounds this user's participant record has already voted in.
        foreach ($this->fetch(objectService: $objectService, schema: 'vote') as $vote) {
            $participant = $this->refId(ref: $vote['participant'] ?? null);
            if ($participant === '' || in_array($participant, $participantIds, true) === false) {
                continue;
            }

            $roundId = $this->refId(ref: $vote['votingRound'] ?? null);
            if ($roundId !== '') {
                unset($openRoundIds[$roundId]);
            }
        }

        return count($openRoundIds);

    }//end countPendingVotes()

    /**
     * Resolve the soonest future lifecycle=scheduled meeting the user is in.
     *
     * @param object   $objectService  OpenRegister ObjectService instance
     * @param string[] $participantIds Participant record ids for the current user
     * @param int      $now            Current unix timestamp
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return array<string,mixed>|null The next meeting payload, or null
     */
    public function resolveNextMeeting(object $objectService, array $participantIds, int $now): ?array
    {
        $meetingIds = $this->resolveParticipatedMeetingIds(objectService: $objectService, participantIds: $participantIds);

        $best     = null;
        $bestTime = null;
        foreach ($this->fetch(objectService: $objectService, schema: 'meeting') as $meeting) {
            if ((string) ($meeting['lifecycle'] ?? '') !== 'scheduled') {
                continue;
            }

            $scheduled = (string) ($meeting['scheduledDate'] ?? '');
            if ($scheduled === '') {
                continue;
            }

            try {
                $ts = (new DateTimeImmutable($scheduled))->getTimestamp();
            } catch (\Throwable) {
                continue;
            }

            if ($ts < $now) {
                continue;
            }

            // When the user has participant records, restrict to meetings they
            // are in; otherwise (no participant records) show none.
            $meetingId = $this->idOf(row: $meeting);
            if (count($meetingIds) === 0 || in_array($meetingId, $meetingIds, true) === false) {
                continue;
            }

            if ($bestTime === null || $ts < $bestTime) {
                $bestTime = $ts;
                $best     = $meeting;
            }
        }//end foreach

        return $best;

    }//end resolveNextMeeting()

    /**
     * Participant record ids linked to the current Nextcloud user.
     *
     * @param object $objectService OpenRegister ObjectService instance
     * @param string $userId        Current Nextcloud user id
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string[] Participant record ids
     */
    private function resolveParticipantIds(object $objectService, string $userId): array
    {
        $ids = [];
        foreach ($this->fetch(objectService: $objectService, schema: 'participant') as $participant) {
            $link = ($participant['nextcloudUserId'] ?? ($participant['owner'] ?? null));
            if (is_string($link) === true && $link === $userId) {
                $pid = $this->idOf(row: $participant);
                if ($pid !== '') {
                    $ids[] = $pid;
                }
            }
        }

        return array_values(array_unique($ids));

    }//end resolveParticipantIds()

    /**
     * Meeting ids the current user's participant records reference.
     *
     * @param object   $objectService  OpenRegister ObjectService instance
     * @param string[] $participantIds Participant record ids for the current user
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string[] Meeting ids
     */
    private function resolveParticipatedMeetingIds(object $objectService, array $participantIds): array
    {
        if (count($participantIds) === 0) {
            return [];
        }

        $meetingIds = [];
        foreach ($this->fetch(objectService: $objectService, schema: 'participant') as $participant) {
            $pid = $this->idOf(row: $participant);
            if ($pid === '' || in_array($pid, $participantIds, true) === false) {
                continue;
            }

            $meetingId = $this->refId(ref: $participant['meeting'] ?? ($participant['relations']['Meeting'][0] ?? null));
            if ($meetingId !== '') {
                $meetingIds[] = $meetingId;
            }
        }

        return array_values(array_unique($meetingIds));

    }//end resolveParticipatedMeetingIds()

    /**
     * Fail-soft findAll returning plain associative-array rows for a schema.
     *
     * @param object $objectService OpenRegister ObjectService instance
     * @param string $schema        Decidesk schema slug
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return array<int, array<string, mixed>> Object rows (empty on failure)
     */
    private function fetch(object $objectService, string $schema): array
    {
        try {
            $rows = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => $schema,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: dashboard widget findAll failed',
                ['schema' => $schema, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ($rows as $entity) {
            $row = $entity;
            if (is_object($entity) === true) {
                $row = (array) $entity->jsonSerialize();
            }

            if (is_array($row) === true) {
                $out[] = $row;
            }
        }

        return $out;

    }//end fetch()

    /**
     * Extract an object id from a row (`id` or `@self.id`).
     *
     * @param array<string, mixed> $row Object row
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The id, or '' when absent
     */
    private function idOf(array $row): string
    {
        $id = ($row['id'] ?? ($row['@self']['id'] ?? ''));
        if (is_scalar($id) === true) {
            return (string) $id;
        }

        return '';

    }//end idOf()

    /**
     * Normalise an OR relation reference (scalar id or `{id: ...}`) to a string.
     *
     * @param mixed $ref The relation reference value
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The referenced id, or '' when absent
     */
    private function refId(mixed $ref): string
    {
        if (is_array($ref) === true) {
            $ref = ($ref['id'] ?? null);
        }

        if (is_scalar($ref) === true) {
            return (string) $ref;
        }

        return '';

    }//end refId()
}//end class
