<?php

/**
 * Meeting Transition Guard
 *
 * Guards the meeting lifecycle's open transition by reading the declaratively
 * computed quorumMet field from the Meeting schema.
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
 * @spec openspec/changes/archive/2026-05-11-quorum-guard-rewrite/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

/**
 * Guard for meeting lifecycle transitions.
 *
 * Reads quorumMet from the declarative Meeting schema
 * (computed by x-openregister-calculations).
 *
 * @spec openspec/changes/archive/2026-05-11-quorum-guard-rewrite/tasks.md#task-1
 */
class MeetingTransitionGuard
{
    /**
     * Check whether the open transition is allowed for the given meeting.
     *
     * Reads the declaratively-computed quorumMet field set by
     * x-openregister-calculations on the Meeting schema (chain spec 1).
     * When quorumRequired is null the calculation returns true, so
     * meetings without a quorum rule are always allowed to open.
     *
     * @param array<string, mixed> $meeting Meeting object array (already loaded by the caller)
     *
     * @spec openspec/changes/archive/2026-05-11-quorum-guard-rewrite/tasks.md#task-1
     *
     * @return bool True when quorum is met or no quorum is required, false otherwise
     */
    public function isOpenAllowed(array $meeting): bool
    {
        return ($meeting['quorumMet'] ?? false) === true;

    }//end isOpenAllowed()
}//end class
