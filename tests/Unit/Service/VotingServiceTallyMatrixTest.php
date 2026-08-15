<?php

/**
 * Exhaustive tally matrix for VotingService::computeResult() (voting-rules-v1).
 *
 * Covers every voteThreshold (4) × abstentionHandling (2) combination with
 * adopted / rejected / boundary distributions, every tie scenario × every
 * tieBreakRule (incl. chair-casting resolution states), the zero-vote and
 * all-abstain edge cases, weighted counts, and the fail-closed defaulting of
 * unknown stored rule values. computeResult() is a pure function of its
 * arguments, so no OpenRegister doubles are needed (issue #90 safe).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
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

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AmendmentOrderService;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\ObjectRelationFilter;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipantUuidLookup;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\VoteCastingService;
use OCA\Decidesk\Service\VotingOpenedNotifier;
use OCA\Decidesk\Service\VotingRoundCloser;
use OCA\Decidesk\Service\VotingRoundOpener;
use OCA\Decidesk\Service\VotingRoundPreflight;
use OCA\Decidesk\Service\VotingRoundProjection;
use OCA\Decidesk\Service\VotingRoundResults;
use OCA\Decidesk\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the threshold/abstention/tie-break tally matrix.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingServiceTallyMatrixTest extends TestCase {

	/**
	 * Build a VotingService — computeResult() never touches the container.
	 *
	 * @return VotingService
	 */
	private function buildService(): VotingService {
		// Only computeResult() is exercised here, but VotingService is a thin
		// facade, so the whole collaborator graph is assembled explicitly where
		// production relies on Nextcloud's constructor auto-wiring.
		$container = $this->createMock(ContainerInterface::class);
		$logger = new NullLogger();
		$motionService = $this->createMock(MotionService::class);
		$participantResolver = $this->createMock(ParticipantResolver::class);
		$templateService = $this->createMock(ProcessTemplateService::class);
		$amendmentOrder = new AmendmentOrderService(container: $container, motionService: $motionService,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$relationFilter = new ObjectRelationFilter();

		return new VotingService(
			opener: new VotingRoundOpener(
				preflight: new VotingRoundPreflight(
		),
				notifier: new VotingOpenedNotifier(
		),
		),
			caster: new VoteCastingService(
		),
			closer: new VotingRoundCloser(
		),
			results: new VotingRoundResults(
		),
			projection: new VotingRoundProjection(container: $container,
		),
			participants: new ParticipantUuidLookup(container: $container,
		),
		);

	}//end buildService()

	/**
	 * Run one matrix case and assert result + base.
	 *
	 * @param int $for Weighted for-votes
	 * @param int $against Weighted against-votes
	 * @param int $abstain Weighted abstentions
	 * @param array<string,mixed> $round Round rules
	 * @param string $expected Expected result
	 * @param int $base Expected computed base
	 * @param string $message Failure label
	 *
	 * @return void
	 */
	private function assertCase(int $for, int $against, int $abstain, array $round, string $expected, int $base, string $message): void {
		$computed = $this->buildService()->computeResult(for: $for, against: $against, abstain: $abstain, round: $round);
		self::assertSame($expected, $computed['result'], $message);
		self::assertSame($base, $computed['base'], $message . ' (base)');

	}//end assertCase()

	/**
	 * The full threshold × abstention matrix (non-tie distributions).
	 *
	 * Rows: [for, against, abstain, voteThreshold, abstentionHandling, expected result, expected base, label].
	 *
	 * @return array<string, array{0:int,1:int,2:int,3:string,4:string,5:string,6:int,7:string}>
	 */
	public static function matrixProvider(): array {
		return [
			// -------- simple-majority × exclude --------
			'simple/exclude adopted' => [4, 3, 2, 'simple-majority', 'exclude', 'adopted', 7, '4>3.5 strict majority'],
			'simple/exclude rejected' => [3, 4, 2, 'simple-majority', 'exclude', 'rejected', 7, 'minority fails'],
			'simple/exclude abstain-neutral' => [2, 1, 17, 'simple-majority', 'exclude', 'adopted', 3, 'abstentions do not block under exclude'],
			// -------- simple-majority × count --------
			'simple/count adopted' => [6, 3, 2, 'simple-majority', 'count', 'adopted', 11, '12>11'],
			'simple/count abstentions-harder' => [4, 3, 3, 'simple-majority', 'count', 'rejected', 10, '8>10 false — abstentions make majority harder'],
			'simple/count exact-half rejected' => [5, 3, 2, 'simple-majority', 'count', 'rejected', 10, '2F == base is NOT a majority and NOT a tie (F != A)'],
			// -------- two-thirds × exclude --------
			'twothirds/exclude spec example' => [14, 5, 1, 'qualified-majority-two-thirds', 'exclude', 'adopted', 19, 'BW 2:42 worked example 73.7% >= 66.7%'],
			'twothirds/exclude boundary adopted' => [2, 1, 0, 'qualified-majority-two-thirds', 'exclude', 'adopted', 3, 'exactly 2/3 carries'],
			'twothirds/exclude rejected' => [3, 2, 0, 'qualified-majority-two-thirds', 'exclude', 'rejected', 5, '60% < 66.7%'],
			'twothirds/exclude no-tie concept' => [5, 5, 0, 'qualified-majority-two-thirds', 'exclude', 'rejected', 10, 'qualified thresholds have no tie: failing = rejected'],
			// -------- two-thirds × count --------
			'twothirds/count delta example' => [13, 4, 3, 'qualified-majority-two-thirds', 'count', 'rejected', 20, '39 < 40 — abstentions counted'],
			'twothirds/count exclude-contrast' => [13, 4, 3, 'qualified-majority-two-thirds', 'exclude', 'adopted', 17, '39 >= 34 — same votes adopted when excluded'],
			'twothirds/count boundary adopted' => [2, 0, 1, 'qualified-majority-two-thirds', 'count', 'adopted', 3, '6 >= 6 exactly'],
			'twothirds/count rejected' => [2, 0, 2, 'qualified-majority-two-thirds', 'count', 'rejected', 4, '6 < 8'],
			// -------- three-quarters × exclude --------
			'threequarters/exclude boundary' => [3, 1, 5, 'qualified-majority-three-quarters', 'exclude', 'adopted', 4, 'exactly 3/4 carries'],
			'threequarters/exclude rejected' => [2, 1, 0, 'qualified-majority-three-quarters', 'exclude', 'rejected', 3, '66.7% < 75%'],
			// -------- three-quarters × count --------
			'threequarters/count boundary' => [3, 0, 1, 'qualified-majority-three-quarters', 'count', 'adopted', 4, '12 >= 12 exactly'],
			'threequarters/count rejected' => [3, 0, 2, 'qualified-majority-three-quarters', 'count', 'rejected', 5, '12 < 15'],
			'threequarters/count vs exclude' => [3, 0, 2, 'qualified-majority-three-quarters', 'exclude', 'adopted', 3, 'same votes carry when abstentions excluded'],
			// -------- unanimous × exclude --------
			'unanimous/exclude adopted' => [5, 0, 2, 'unanimous', 'exclude', 'adopted', 5, 'abstentions do not break unanimity under exclude'],
			'unanimous/exclude rejected' => [5, 1, 0, 'unanimous', 'exclude', 'rejected', 6, 'one against breaks unanimity'],
			// -------- unanimous × count --------
			'unanimous/count abstention-breaks' => [5, 0, 2, 'unanimous', 'count', 'rejected', 7, 'counted abstention breaks unanimity'],
			'unanimous/count adopted' => [5, 0, 0, 'unanimous', 'count', 'adopted', 5, 'all cast votes for'],
			// -------- zero-vote and all-abstain edges (every threshold) --------
			'invalid simple/exclude' => [0, 0, 0, 'simple-majority', 'exclude', 'invalid', 0, 'no votes cast'],
			'invalid twothirds/count' => [0, 0, 0, 'qualified-majority-two-thirds', 'count', 'invalid', 0, 'no votes cast'],
			'invalid threequarters/exclude' => [0, 0, 0, 'qualified-majority-three-quarters', 'exclude', 'invalid', 0, 'no votes cast'],
			'invalid unanimous/count' => [0, 0, 0, 'unanimous', 'count', 'invalid', 0, 'no votes cast'],
			'all-abstain simple/exclude' => [0, 0, 3, 'simple-majority', 'exclude', 'rejected', 0, 'base 0 cannot carry'],
			'all-abstain simple/count' => [0, 0, 3, 'simple-majority', 'count', 'rejected', 3, '0 is no majority of 3'],
			'all-abstain twothirds/exclude' => [0, 0, 3, 'qualified-majority-two-thirds', 'exclude', 'rejected', 0, 'base 0 cannot carry'],
			'all-abstain unanimous/exclude' => [0, 0, 3, 'unanimous', 'exclude', 'rejected', 0, 'unanimity is not vacuous'],
			'all-abstain unanimous/count' => [0, 0, 3, 'unanimous', 'count', 'rejected', 3, '0 != 3'],
		];

	}//end matrixProvider()

	/**
	 * Every threshold × abstention combination produces the documented result.
	 *
	 * @dataProvider matrixProvider
	 *
	 * @param int $for Weighted for-votes
	 * @param int $against Weighted against-votes
	 * @param int $abstain Weighted abstentions
	 * @param string $voteThreshold Threshold under test
	 * @param string $abstentionHandling Abstention mode under test
	 * @param string $expected Expected result
	 * @param int $base Expected computed base
	 * @param string $label Failure label
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testTallyMatrix(int $for, int $against, int $abstain, string $voteThreshold, string $abstentionHandling, string $expected, int $base, string $label): void {
		$this->assertCase(
			for: $for,
			against: $against,
			abstain: $abstain,
			round: [
				'voteThreshold' => $voteThreshold,
				'abstentionHandling' => $abstentionHandling,
				'tieBreakRule' => 'rejected',
			],
			expected: $expected,
			base: $base,
			message: $label
		);

	}//end testTallyMatrix()

	/**
	 * Tie scenarios: F == A > 0 under simple majority, for both abstention
	 * modes and all three tie-break rules incl. chair-casting resolution.
	 *
	 * @return array<string, array{0:string,1:string,2:string|null,3:string}>
	 */
	public static function tieProvider(): array {
		return [
			// [abstentionHandling, tieBreakRule, chairCastingVote, expected].
			'exclude/rejected' => ['exclude', 'rejected', null, 'rejected'],
			'count/rejected' => ['count', 'rejected', null, 'rejected'],
			'exclude/chair pending' => ['exclude', 'chair-decides', null, 'tied'],
			'count/chair pending' => ['count', 'chair-decides', null, 'tied'],
			'exclude/chair casts for' => ['exclude', 'chair-decides', 'for', 'adopted'],
			'count/chair casts for' => ['count', 'chair-decides', 'for', 'adopted'],
			'exclude/chair casts against' => ['exclude', 'chair-decides', 'against', 'rejected'],
			'count/chair casts against' => ['count', 'chair-decides', 'against', 'rejected'],
			'exclude/revote' => ['exclude', 'revote', null, 'tied'],
			'count/revote' => ['count', 'revote', null, 'tied'],
		];

	}//end tieProvider()

	/**
	 * The spec's 10–10 tie resolves per the configured tie-break rule.
	 *
	 * @dataProvider tieProvider
	 *
	 * @param string $abstentionHandling Abstention mode under test
	 * @param string $tieBreakRule Tie-break rule under test
	 * @param string|null $chairCastingVote Recorded casting vote (chair-decides only)
	 * @param string $expected Expected result
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testTieScenarios(string $abstentionHandling, string $tieBreakRule, ?string $chairCastingVote, string $expected): void {
		$round = [
			'voteThreshold' => 'simple-majority',
			'abstentionHandling' => $abstentionHandling,
			'tieBreakRule' => $tieBreakRule,
		];

		if ($chairCastingVote !== null) {
			$round['chairCastingVote'] = $chairCastingVote;
		}

		$expectedBase = 20;
		if ($abstentionHandling === 'count') {
			$expectedBase = 22;
		}

		$this->assertCase(
			for: 10,
			against: 10,
			abstain: 2,
			round: $round,
			expected: $expected,
			base: $expectedBase,
			message: "10-10 tie / {$abstentionHandling} / {$tieBreakRule} / casting=" . ($chairCastingVote ?? 'none')
		);

	}//end testTieScenarios()

	/**
	 * A chair casting vote is ignored when the vote is not actually tied —
	 * it only ever resolves the tie path (fail closed).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testChairCastingVoteIgnoredWithoutTie(): void {
		$this->assertCase(
			for: 3,
			against: 5,
			abstain: 0,
			round: [
				'voteThreshold' => 'simple-majority',
				'abstentionHandling' => 'exclude',
				'tieBreakRule' => 'chair-decides',
				'chairCastingVote' => 'for',
			],
			expected: 'rejected',
			base: 8,
			message: 'A minority is never adopted by a casting vote'
		);

	}//end testChairCastingVoteIgnoredWithoutTie()

	/**
	 * Rounds created before voting-rules-v1 (no rule fields) keep the legacy
	 * semantics for clear majorities, and a bare tie now fails the motion
	 * under the default tie-break rule.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testLegacyRoundsDefaultToSimpleMajorityExcludeRejected(): void {
		$this->assertCase(for: 4, against: 3, abstain: 1, round: [], expected: 'adopted', base: 7, message: 'legacy adopted');
		$this->assertCase(for: 3, against: 4, abstain: 1, round: [], expected: 'rejected', base: 7, message: 'legacy rejected');
		$this->assertCase(for: 3, against: 3, abstain: 1, round: [], expected: 'rejected', base: 6, message: 'legacy tie fails the motion (default rule)');

	}//end testLegacyRoundsDefaultToSimpleMajorityExcludeRejected()

	/**
	 * Unknown stored rule values fall back to the strict defaults (fail closed).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testUnknownRuleValuesFailClosedToDefaults(): void {
		$computed = $this->buildService()->computeResult(
			for: 3,
			against: 3,
			abstain: 4,
			round: [
				'voteThreshold' => 'plurality',
				'abstentionHandling' => 'half',
				'tieBreakRule' => 'coin-flip',
			]
		);

		self::assertSame('rejected', $computed['result'], 'tie under defaulted rules fails the motion');
		self::assertSame(6, $computed['base'], 'abstentions defaulted to exclude');
		self::assertSame('simple-majority', $computed['voteThreshold']);
		self::assertSame('exclude', $computed['abstentionHandling']);
		self::assertSame('rejected', $computed['tieBreakRule']);

	}//end testUnknownRuleValuesFailClosedToDefaults()

	/**
	 * Vote weights are honoured in the counts and the base.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testWeightedCountsHonoured(): void {
		// Weighted counts arrive pre-aggregated: 1 voter with weight 3 'for',
		// 2 voters weight 1 'against' -> F=3, A=2 — adopted under simple majority.
		$this->assertCase(
			for: 3,
			against: 2,
			abstain: 0,
			round: ['voteThreshold' => 'simple-majority', 'abstentionHandling' => 'exclude', 'tieBreakRule' => 'rejected'],
			expected: 'adopted',
			base: 5,
			message: 'weighted majority carries'
		);

	}//end testWeightedCountsHonoured()
}//end class
