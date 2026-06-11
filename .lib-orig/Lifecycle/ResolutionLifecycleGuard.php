<?php

/**
 * Resolution Lifecycle Guard
 *
 * Composes the quorum check and the conflict-of-interest gate that protect
 * Resolution state transitions. The guard is consulted by ResolutionService
 * before opening a vote or marking a resolution adopted.
 *
 * @category Lifecycle
 * @package  OCA\Decidesk\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-lifecycle-guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

use OCA\Decidesk\Service\ConflictOfInterestService;
use OCA\Decidesk\Service\QuorumVerificationService;

/**
 * Guard for Resolution lifecycle transitions.
 *
 * Two checks are composed:
 *  - Quorum: the parent meeting must satisfy its quorum rule before a vote
 *    on a resolution may be opened.
 *  - Conflict of interest: a board member who has declared a
 *    `recused-from-vote` conflict on the resolution's agenda item must not
 *    be allowed to cast a vote.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-lifecycle-guard
 */
class ResolutionLifecycleGuard
{
    /**
     * Constructor for ResolutionLifecycleGuard.
     *
     * @param QuorumVerificationService $quorumService   Quorum dependency
     * @param ConflictOfInterestService $conflictService Conflict-of-interest dependency
     */
    public function __construct(
        private readonly QuorumVerificationService $quorumService,
        private readonly ConflictOfInterestService $conflictService,
    ) {
    }//end __construct()

    /**
     * Decide whether the vote on a resolution may be opened. The parent
     * meeting must satisfy its quorum rule.
     *
     * @param string $meetingId UUID of the meeting hosting the resolution
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-lifecycle-guard
     *
     * @return array{allowed: bool, reason: string, quorum: array<string, mixed>}
     */
    public function canOpenVote(string $meetingId): array
    {
        $quorum = $this->quorumService->computeQuorum($meetingId);

        if ($quorum['met'] === true) {
            return [
                'allowed' => true,
                'reason'  => 'Quorum met.',
                'quorum'  => $quorum,
            ];
        }

        return [
            'allowed' => false,
            'reason'  => sprintf(
                'Quorum not met (%d/%d, threshold %d).',
                (int) $quorum['present'],
                (int) $quorum['total'],
                (int) $quorum['threshold']
            ),
            'quorum'  => $quorum,
        ];

    }//end canOpenVote()

    /**
     * Decide whether the given board member may cast a vote on the resolution.
     * The check inspects active conflicts and blocks any member whose
     * actionTaken is `recused-from-vote`.
     *
     * @param string $boardMemberId UUID of the board member
     * @param string $agendaItemId  UUID of the agenda item the resolution belongs to
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-lifecycle-guard
     *
     * @return array{allowed: bool, reason: string, conflict: array|null}
     */
    public function canCastVote(string $boardMemberId, string $agendaItemId): array
    {
        $conflict = $this->conflictService->getActiveConflicts($boardMemberId, $agendaItemId);

        if ($conflict === null) {
            return [
                'allowed'  => true,
                'reason'   => 'No conflict on record.',
                'conflict' => null,
            ];
        }

        $action = (string) ($conflict['actionTaken'] ?? 'no-action-needed');

        if (in_array($action, ['recused-from-vote', 'recused-from-discussion'], true) === true) {
            return [
                'allowed'  => false,
                'reason'   => 'Board member is recused on this agenda item ('.$action.').',
                'conflict' => $conflict,
            ];
        }

        return [
            'allowed'  => true,
            'reason'   => 'Conflict declared but action does not block voting.',
            'conflict' => $conflict,
        ];

    }//end canCastVote()
}//end class
