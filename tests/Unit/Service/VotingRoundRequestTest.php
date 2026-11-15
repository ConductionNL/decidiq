<?php

/**
 * Unit tests for VotingRoundRequest.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingRoundRequest;
use OCA\Decidiq\Service\VotingRoundRules;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VotingRoundRequest.
 *
 * @covers \OCA\Decidiq\Service\VotingRoundRequest
 * @uses   \OCA\Decidiq\Service\VotingRoundRules
 */
class VotingRoundRequestTest extends TestCase {

	/**
	 * Without opening() a request is a fresh round with defaults.
	 *
	 * @return void
	 */
	public function testABareRequestIsAFreshRoundWithDefaults(): void {
		$request = new VotingRoundRequest(motionId: 'm-1', meetingId: 'meet-1', votingMethod: 'show-of-hands', isSecret: true);

		self::assertNull($request->closedAt());
		self::assertSame([], $request->presetParticipantIds());
		self::assertTrue($request->isFreshRound());
		self::assertEquals(new VotingRoundRules(), $request->rules());
	}//end testABareRequestIsAFreshRoundWithDefaults()

	/**
	 * opening() returns a completed copy and leaves the original as it was.
	 *
	 * @return void
	 */
	public function testOpeningLeavesTheOriginalUntouched(): void {
		$bare  = new VotingRoundRequest(motionId: 'm-1', meetingId: 'meet-1', votingMethod: 'show-of-hands', isSecret: false);
		$rules = new VotingRoundRules(tieBreakRule: 'revote');
		$full  = $bare->opening(closedAt: '2026-06-12T18:00:00+00:00', presetParticipantIds: ['p-1'], revoteOfRoundId: 'r-1', rules: $rules);

		self::assertSame('r-1', $full->revoteOfRoundId());
		self::assertFalse($full->isFreshRound());
		self::assertSame($rules, $full->rules());
		self::assertTrue($bare->isFreshRound());
		self::assertNull($bare->closedAt());
		self::assertSame([], $bare->presetParticipantIds());
	}//end testOpeningLeavesTheOriginalUntouched()
}//end class
