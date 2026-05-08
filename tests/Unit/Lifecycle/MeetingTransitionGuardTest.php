<?php

/**
 * Unit tests for MeetingTransitionGuard.
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
 * @spec openspec/changes/spec/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Lifecycle;

use OCA\Decidesk\Lifecycle\MeetingTransitionGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MeetingTransitionGuard.isOpenAllowed.
 *
 * Verifies that the guard reads the declarative quorumMet field
 * rather than calling QuorumService.
 *
 * @spec openspec/changes/spec/tasks.md#task-4
 */
class MeetingTransitionGuardTest extends TestCase
{

    /**
     * Guard under test.
     *
     * @var MeetingTransitionGuard
     */
    private MeetingTransitionGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new MeetingTransitionGuard();

    }//end setUp()

    /**
     * Meeting with quorumMet = true allows the open transition.
     *
     * @spec openspec/changes/spec/tasks.md#task-4
     *
     * @return void
     */
    public function testOpenAllowedWhenQuorumMet(): void
    {
        $meeting = [
            'id'             => 'aaa-001',
            'lifecycle'      => 'scheduled',
            'quorumRequired' => 5,
            'quorumMet'      => true,
        ];

        self::assertTrue(condition: $this->guard->isOpenAllowed(meeting: $meeting));

    }//end testOpenAllowedWhenQuorumMet()

    /**
     * Meeting with quorumMet = false blocks the open transition.
     *
     * @spec openspec/changes/spec/tasks.md#task-4
     *
     * @return void
     */
    public function testOpenBlockedWhenQuorumNotMet(): void
    {
        $meeting = [
            'id'             => 'aaa-002',
            'lifecycle'      => 'scheduled',
            'quorumRequired' => 5,
            'quorumMet'      => false,
        ];

        self::assertFalse(condition: $this->guard->isOpenAllowed(meeting: $meeting));

    }//end testOpenBlockedWhenQuorumNotMet()

    /**
     * Meeting with quorumRequired = null and quorumMet = true allows open.
     *
     * When no quorum rule is configured, the x-openregister-calculations
     * expression sets quorumMet = true. The guard honours that signal.
     *
     * @spec openspec/changes/spec/tasks.md#task-4
     *
     * @return void
     */
    public function testOpenAllowedWhenNoQuorumRequired(): void
    {
        $meeting = [
            'id'             => 'aaa-003',
            'lifecycle'      => 'scheduled',
            'quorumRequired' => null,
            'quorumMet'      => true,
        ];

        self::assertTrue(condition: $this->guard->isOpenAllowed(meeting: $meeting));

    }//end testOpenAllowedWhenNoQuorumRequired()

    /**
     * Meeting without quorumMet key defaults to false (safe default).
     *
     * @spec openspec/changes/spec/tasks.md#task-4
     *
     * @return void
     */
    public function testOpenBlockedWhenQuorumMetMissing(): void
    {
        $meeting = [
            'id'        => 'aaa-004',
            'lifecycle' => 'scheduled',
        ];

        self::assertFalse(condition: $this->guard->isOpenAllowed(meeting: $meeting));

    }//end testOpenBlockedWhenQuorumMetMissing()
}//end class
