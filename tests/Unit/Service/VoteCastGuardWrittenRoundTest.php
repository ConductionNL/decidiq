<?php

/**
 * Tests that the meeting cast path refuses a written round.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VoteCastGuard;
use OCA\Decidiq\Service\VoterTokenSecret;
use OCA\Decidiq\Tests\Unit\Support\WrittenRoundWorld;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A written round never reaches the meeting-participant path, whose meeting
 * check is skipped for a round without a meeting.
 *
 * @covers \OCA\Decidiq\Service\VoteCastGuard
 */
class VoteCastGuardWrittenRoundTest extends TestCase {

	/**
	 * The guard over a world with an open written round and an open meeting round.
	 *
	 * @return VoteCastGuard
	 */
	private function guard(): VoteCastGuard {
		$world = new WrittenRoundWorld(test: $this);
		$world->put('voting-round', ['id' => 'written', 'procedure' => 'written', 'openedAt' => '2026-03-09T09:00:00+00:00', 'closedAt' => null]);
		$world->put('voting-round', ['id' => 'meeting', 'openedAt' => '2026-03-09T09:00:00+00:00', 'closedAt' => null]);

		return new VoteCastGuard(
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
			relationFilter: $this->createMock(ObjectRelationFilter::class),
			tokens: $this->createMock(VoterTokenSecret::class),
			participantResolver: $this->createMock(ParticipantResolver::class),
			amendmentOrder: $this->createMock(AmendmentOrderService::class),
			objectService: $world->objectService(),
		);
	}//end guard()

	/**
	 * The meeting round still loads; the written one is refused.
	 *
	 * @return void
	 */
	public function testAWrittenRoundIsRefusedOnTheMeetingPath(): void {
		$guard = $this->guard();

		$this->assertSame('meeting', $guard->loadOpenRound(votingRoundId: 'meeting')['id']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A written round is answered by the members it was put to, not as a meeting participant');
		$guard->loadOpenRound(votingRoundId: 'written');
	}//end testAWrittenRoundIsRefusedOnTheMeetingPath()
}//end class
