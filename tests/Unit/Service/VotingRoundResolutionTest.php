<?php

/**
 * Unit tests for VotingRoundResolution — the payload a new voting round is saved with.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingRoundRequest;
use OCA\Decidiq\Service\VotingRoundResolution;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a round names the meeting it was opened in.
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 */
class VotingRoundResolutionTest extends TestCase {

	/**
	 * Build a resolution with default rules.
	 *
	 * @return VotingRoundResolution
	 */
	private function resolution(): VotingRoundResolution {
		return new VotingRoundResolution(
			rules: ['voteThreshold' => 'simple-majority', 'abstentionHandling' => 'exclude', 'tieBreakRule' => 'rejected'],
			quorumWith: true,
			participantIds: []
		);
	}//end resolution()

	/**
	 * A round opened for a meeting carries that meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testRoundPayloadCarriesTheMeeting(): void {
		$request = new VotingRoundRequest(motionId: 'motion-1', meetingId: 'meeting-1', votingMethod: 'for-against-abstain', isSecret: false);

		$round = $this->resolution()->roundPayload(request: $request);

		$this->assertSame(expected: 'meeting-1', actual: $round['meeting']);

	}//end testRoundPayloadCarriesTheMeeting()

	/**
	 * A request without a meeting writes no meeting field.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testRoundPayloadWithoutMeetingHasNoMeetingField(): void {
		$request = new VotingRoundRequest(motionId: 'motion-1', meetingId: '', votingMethod: 'for-against-abstain', isSecret: false);

		$round = $this->resolution()->roundPayload(request: $request);

		$this->assertArrayNotHasKey(key: 'meeting', array: $round);

	}//end testRoundPayloadWithoutMeetingHasNoMeetingField()

}//end class
