<?php

/**
 * Unit tests for PaperVoteRecorder::recordVotes(): a named vote taken on paper,
 * entered by the chair or the secretary (board DcStemmenInvoeren).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PaperVoteRecorder;
use OCA\Decidiq\Service\VoteCastingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each paper vote goes through the same caster a member's own vote does.
 */
class PaperVoteRecorderTest extends TestCase {

	/**
	 * Build the recorder around a caster double.
	 *
	 * @param VoteCastingService $caster The caster.
	 *
	 * @return PaperVoteRecorder
	 */
	private function service(VoteCastingService $caster): PaperVoteRecorder {
		return new PaperVoteRecorder(caster: $caster);
	}//end service()

	/**
	 * Every vote is cast for its member through the caster, which checks the
	 * round is open, the member sits in the meeting and is not recused; a
	 * refusal is reported per member and the others are still recorded.
	 *
	 * @return void
	 */
	public function testEachPaperVoteIsCastForItsMemberAndRefusalsAreReported(): void {
		$cast = [];
		$caster = $this->createMock(originalClassName: VoteCastingService::class);
		$caster->method('castVote')->willReturnCallback(
			function (
				string $votingRoundId,
				string $participantId,
				string $value,
				bool $isProxy,
				?string $delegatorId,
				?string $callerUid=null
			) use (&$cast): array {
				if ($participantId === 'p-recused') {
					throw new RuntimeException('Recused on this matter');
				}

				$cast[] = [$votingRoundId, $participantId, $value, $isProxy, $delegatorId, $callerUid];

				return ['id' => 'v-' . $participantId];
			}
		);

		$result = $this->service(caster: $caster)->recordVotes(
			votingRoundId: 'r-1',
			votes: [
				['participant' => 'p-1', 'value' => 'for'],
				['participant' => 'p-recused', 'value' => 'against'],
				['participant' => 'p-2', 'value' => 'abstain'],
			],
			callerUid: 'griffier'
		);

		self::assertSame(
			expected: [
				['r-1', 'p-1', 'for', false, null, 'griffier'],
				['r-1', 'p-2', 'abstain', false, null, 'griffier'],
			],
			actual: $cast
		);
		self::assertSame(
			expected: ['recorded' => 2, 'refused' => [['participant' => 'p-recused', 'message' => 'Recused on this matter']]],
			actual: $result
		);
	}//end testEachPaperVoteIsCastForItsMemberAndRefusalsAreReported()

	/**
	 * A row without a member or with another value than for, against or
	 * abstain is refused without reaching the caster.
	 *
	 * @return void
	 */
	public function testAMalformedRowIsRefused(): void {
		$caster = $this->createMock(originalClassName: VoteCastingService::class);
		$caster->expects($this->never())->method('castVote');

		$result = $this->service(caster: $caster)->recordVotes(
			votingRoundId: 'r-1',
			votes: [
				['participant' => '', 'value' => 'for'],
				['participant' => 'p-1', 'value' => 'maybe'],
			],
			callerUid: 'griffier'
		);

		self::assertSame(expected: 0, actual: $result['recorded']);
		self::assertCount(expectedCount: 2, haystack: $result['refused']);
	}//end testAMalformedRowIsRefused()
}//end class
