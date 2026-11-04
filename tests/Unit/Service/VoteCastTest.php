<?php

/**
 * Unit tests for VoteCast.
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

use OCA\Decidiq\Service\VoteCast;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VoteCast.
 *
 * @covers \OCA\Decidiq\Service\VoteCast
 */
class VoteCastTest extends TestCase {

	/**
	 * A plain ballot counts for its caster and is not delegated.
	 *
	 * @return void
	 */
	public function testAPlainBallotCountsForItsCaster(): void {
		$cast = new VoteCast(votingRoundId: 'r-1', participantId: 'p-1', value: 'for');

		self::assertFalse($cast->isProxy());
		self::assertNull($cast->delegatorId());
		self::assertFalse($cast->isDelegated());
		self::assertFalse($cast->isSecret());
		self::assertNull($cast->ranking());
		self::assertSame('p-1', $cast->ballotOwner());
	}//end testAPlainBallotCountsForItsCaster()

	/**
	 * A proxy ballot is a new ballot; the original stays a plain one.
	 *
	 * @return void
	 */
	public function testAProxyBallotLeavesTheOriginalUntouched(): void {
		$plain = new VoteCast(votingRoundId: 'r-1', participantId: 'p-1', value: 'for');
		$proxy = $plain->asProxy(delegatorId: 'p-2');

		self::assertTrue($proxy->isDelegated());
		self::assertSame('p-2', $proxy->ballotOwner());
		self::assertFalse($plain->isProxy());
		self::assertSame('p-1', $plain->ballotOwner());
	}//end testAProxyBallotLeavesTheOriginalUntouched()

	/**
	 * A proxy ballot without a delegator is a proxy that counts for its caster.
	 *
	 * @return void
	 */
	public function testAProxyWithoutDelegatorIsNotDelegated(): void {
		$proxy = (new VoteCast(votingRoundId: 'r-1', participantId: 'p-1', value: 'for'))->asProxy(delegatorId: null);

		self::assertTrue($proxy->isProxy());
		self::assertFalse($proxy->isDelegated());
		self::assertSame('p-1', $proxy->ballotOwner());
	}//end testAProxyWithoutDelegatorIsNotDelegated()

	/**
	 * Settling a ballot keeps a ranking on a ranked value only, and leaves the
	 * original as it was.
	 *
	 * @return void
	 */
	public function testSettlingKeepsTheRankingOfARankedBallotOnly(): void {
		$cast = new VoteCast(votingRoundId: 'r-1', participantId: 'p-1', value: 'for', ranking: ['b', 'a']);

		$ranked = $cast->forRound(value: 'ranked', isSecret: true);
		self::assertSame(['b', 'a'], $ranked->ranking());
		self::assertSame('ranked', $ranked->value());
		self::assertTrue($ranked->isSecret());

		$plain = $cast->forRound(value: 'for', isSecret: false);
		self::assertNull($plain->ranking());
		self::assertSame(['b', 'a'], $cast->ranking());
		self::assertFalse($cast->isSecret());
	}//end testSettlingKeepsTheRankingOfARankedBallotOnly()
}//end class
