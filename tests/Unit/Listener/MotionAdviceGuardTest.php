<?php

/**
 * Unit tests for MotionAdviceGuard.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use JsonSerializable;
use OCA\Decidiq\Listener\MotionAdviceGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the one-advice-per-resident guard on motion votes.
 *
 * @covers \OCA\Decidiq\Listener\MotionAdviceGuard
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
 */
class MotionAdviceGuardTest extends TestCase {
	/**
	 * An object service stub recording its findAll calls.
	 */
	private function objectService(array $earlier, ?array &$calls = []): object {
		return new class($earlier, $calls) {
			public function __construct(private array $earlier, private ?array &$calls) {
			}

			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				$this->calls[] = [$config, $_rbac, $_multitenancy];
				return $this->earlier;
			}
		};
	}

	public function testAppliesOnlyToRowsNamingAMotion(): void {
		$guard = new MotionAdviceGuard();

		$this->assertTrue($guard->appliesTo(['motionId' => 'm-1']));
		$this->assertTrue($guard->appliesTo(['motionId' => 12]));
		$this->assertFalse($guard->appliesTo(['motionId' => '  ']));
		$this->assertFalse($guard->appliesTo(['motionId' => ['m-1']]));
		$this->assertFalse($guard->appliesTo([]));
	}

	public function testRefusesAnUnknownVoteValue(): void {
		$calls = [];
		$this->assertSame(
			'Your advice on a motion must be voor, tegen or onthoud',
			(new MotionAdviceGuard())->refusal(['motionId' => 'm', 'voterId' => 'v', 'voteValue' => 'yes'], $this->objectService([], $calls))
		);
		$this->assertSame([], $calls);
	}

	public function testRefusesAVoteWithoutVoter(): void {
		$this->assertSame(
			'A vote on a motion must name its voter',
			(new MotionAdviceGuard())->refusal(['motionId' => 'm', 'voterId' => ' ', 'voteValue' => 'voor'], $this->objectService([]))
		);
	}

	public function testAcceptsAFirstVoteAndSearchesWithoutAccessFilters(): void {
		$calls = [];

		$this->assertNull(
			(new MotionAdviceGuard())->refusal(
				['motionId' => ' m-1 ', 'voterId' => 'v-1', 'voteValue' => 'onthoud'],
				$this->objectService([], $calls)
			)
		);
		$this->assertSame(
			[
				[
					['filters' => ['register' => 'decidiq', 'schema' => 'citizen-vote', 'motionId' => 'm-1', 'voterId' => 'v-1']],
					false,
					false,
				],
			],
			$calls
		);
	}

	public function testRefusesASecondVoteFromTheSameVoter(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['motionId' => 'm-1', 'voterId' => 'v-1'];
			}
		};

		$this->assertSame(
			'You have already given your advice on this motion',
			(new MotionAdviceGuard())->refusal(['motionId' => 'm-1', 'voterId' => 'v-1', 'voteValue' => 'tegen'], $this->objectService([$entity]))
		);
	}

	public function testAcceptsAnArrayRowMatchingTheVote(): void {
		$this->assertSame(
			'You have already given your advice on this motion',
			(new MotionAdviceGuard())->refusal(
				['motionId' => 'm-1', 'voterId' => 'v-1', 'voteValue' => 'voor'],
				$this->objectService([['motionId' => 'm-1', 'voterId' => 'v-1']])
			)
		);
	}

	public function testIgnoresResultsForAnotherVoterOrMotion(): void {
		$this->assertNull(
			(new MotionAdviceGuard())->refusal(
				['motionId' => 'm-1', 'voterId' => 'v-1', 'voteValue' => 'voor'],
				$this->objectService([['motionId' => 'm-1', 'voterId' => 'v-2'], ['motionId' => 'm-2', 'voterId' => 'v-1'], []])
			)
		);
	}
}
