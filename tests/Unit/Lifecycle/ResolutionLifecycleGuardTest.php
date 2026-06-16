<?php

/**
 * Unit tests for ResolutionLifecycleGuard.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Lifecycle
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

namespace OCA\Decidesk\Tests\Unit\Lifecycle;

use OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCA\Decidesk\Service\QuorumVerificationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResolutionLifecycleGuard.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-lifecycle-guard
 */
class ResolutionLifecycleGuardTest extends TestCase
{


    /**
     * canOpenVote allows when quorum is met.
     *
     * @return void
     */
    public function testCanOpenVoteAllowsWhenQuorumMet(): void
    {
        $quorum = $this->createMock(QuorumVerificationService::class);
        $quorum->method('computeQuorum')->willReturn([
            'total' => 5, 'present' => 3, 'threshold' => 3, 'met' => true,
        ]);

        $conflict = $this->createMock(ConflictOfInterestService::class);

        $guard  = new ResolutionLifecycleGuard($quorum, $conflict);
        $result = $guard->canOpenVote('m1');

        $this->assertTrue($result['allowed']);
        $this->assertSame('Quorum met.', $result['reason']);

    }//end testCanOpenVoteAllowsWhenQuorumMet()


    /**
     * canOpenVote rejects when quorum is short and explains the gap.
     *
     * @return void
     */
    public function testCanOpenVoteRejectsWhenQuorumShort(): void
    {
        $quorum = $this->createMock(QuorumVerificationService::class);
        $quorum->method('computeQuorum')->willReturn([
            'total' => 5, 'present' => 2, 'threshold' => 3, 'met' => false,
        ]);

        $conflict = $this->createMock(ConflictOfInterestService::class);

        $guard  = new ResolutionLifecycleGuard($quorum, $conflict);
        $result = $guard->canOpenVote('m1');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Quorum not met', $result['reason']);
        $this->assertStringContainsString('2/5', $result['reason']);

    }//end testCanOpenVoteRejectsWhenQuorumShort()


    /**
     * canCastVote allows when no conflict is on record.
     *
     * @return void
     */
    public function testCanCastVoteAllowsWithoutConflict(): void
    {
        $quorum   = $this->createMock(QuorumVerificationService::class);
        $conflict = $this->createMock(ConflictOfInterestService::class);
        $conflict->method('getActiveConflicts')->willReturn(null);

        $guard  = new ResolutionLifecycleGuard($quorum, $conflict);
        $result = $guard->canCastVote('m1', 'agenda-1');

        $this->assertTrue($result['allowed']);

    }//end testCanCastVoteAllowsWithoutConflict()


    /**
     * canCastVote rejects when actionTaken is recused-from-vote.
     *
     * @return void
     */
    public function testCanCastVoteRejectsRecusedMember(): void
    {
        $quorum   = $this->createMock(QuorumVerificationService::class);
        $conflict = $this->createMock(ConflictOfInterestService::class);
        $conflict->method('getActiveConflicts')->willReturn(['actionTaken' => 'recused-from-vote']);

        $guard  = new ResolutionLifecycleGuard($quorum, $conflict);
        $result = $guard->canCastVote('m1', 'agenda-1');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('recused-from-vote', $result['reason']);

    }//end testCanCastVoteRejectsRecusedMember()


    /**
     * canCastVote allows when actionTaken is disclosed-and-participated.
     *
     * @return void
     */
    public function testCanCastVoteAllowsDisclosedAndParticipated(): void
    {
        $quorum   = $this->createMock(QuorumVerificationService::class);
        $conflict = $this->createMock(ConflictOfInterestService::class);
        $conflict->method('getActiveConflicts')->willReturn(['actionTaken' => 'disclosed-and-participated']);

        $guard  = new ResolutionLifecycleGuard($quorum, $conflict);
        $result = $guard->canCastVote('m1', 'agenda-1');

        $this->assertTrue($result['allowed']);

    }//end testCanCastVoteAllowsDisclosedAndParticipated()


}//end class
