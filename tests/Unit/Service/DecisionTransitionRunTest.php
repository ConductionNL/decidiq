<?php

/**
 * Unit tests for DecisionTransitionRun.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decision-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DecisionTransitionRun;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DecisionTransitionRun.
 *
 * @covers \OCA\Decidiq\Service\DecisionTransitionRun
 */
class DecisionTransitionRunTest extends TestCase {

	/**
	 * entering() returns a run with its states and leaves the original empty.
	 *
	 * @return void
	 */
	public function testEnteringLeavesTheOriginalUntouched(): void {
		$bare = new DecisionTransitionRun(decisionId: 'd-1', action: 'decide', currentUserId: 'chair-uid', comment: 'ok');
		$run  = $bare->entering(transition: ['from' => ['voting', 'deliberating'], 'to' => 'decided'], currentLifecycle: 'voting');

		self::assertTrue($run->startsFromAllowedState());
		self::assertSame('voting', $run->currentLifecycle());
		self::assertSame('decided', $run->newState());
		self::assertSame('', $bare->newState());
		self::assertSame('', $bare->currentLifecycle());
		self::assertFalse($bare->startsFromAllowedState());
	}//end testEnteringLeavesTheOriginalUntouched()

	/**
	 * within() returns a run with its guard context and leaves the original without one.
	 *
	 * @return void
	 */
	public function testWithinLeavesTheOriginalUntouched(): void {
		$bare = new DecisionTransitionRun(decisionId: 'd-1', action: 'decide', currentUserId: 'chair-uid', comment: '');
		$run  = $bare->within(meeting: ['id' => 'm-1'], domain: 'legislative', policyOverride: ['quorumEnforced' => false]);

		self::assertSame(['id' => 'm-1'], $run->meeting());
		self::assertSame('legislative', $run->domain());
		self::assertSame(['quorumEnforced' => false], $run->policyOverride());
		self::assertNull($bare->meeting());
		self::assertSame('', $bare->domain());
		self::assertNull($bare->policyOverride());
	}//end testWithinLeavesTheOriginalUntouched()

	/**
	 * A state outside the transition's from-list, or a case variant of one, does not start it.
	 *
	 * @return void
	 */
	public function testOnlyAListedStateStartsTheTransition(): void {
		$bare = new DecisionTransitionRun(decisionId: 'd-1', action: 'enact', currentUserId: null, comment: '');

		self::assertFalse($bare->entering(transition: ['from' => ['decided'], 'to' => 'enacted'], currentLifecycle: 'draft')->startsFromAllowedState());
		self::assertFalse($bare->entering(transition: ['from' => ['decided'], 'to' => 'enacted'], currentLifecycle: 'Decided')->startsFromAllowedState());
	}//end testOnlyAListedStateStartsTheTransition()
}//end class
