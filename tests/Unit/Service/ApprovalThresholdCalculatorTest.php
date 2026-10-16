<?php

/**
 * Unit tests for ApprovalThresholdCalculator.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\ApprovalThresholdCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the three threshold kinds, the recomputation in both directions, and
 * the point at which refusals decide a step.
 */
final class ApprovalThresholdCalculatorTest extends TestCase {
	/**
	 * The calculator under test.
	 *
	 * @var ApprovalThresholdCalculator
	 */
	private ApprovalThresholdCalculator $calculator;

	/**
	 * Build the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new ApprovalThresholdCalculator();
	}//end setUp()

	/**
	 * A number of grants, as actions.
	 *
	 * @param int $granted How many granted.
	 * @param int $refused How many refused.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function actions(int $granted, int $refused = 0): array {
		$actions = [];
		for ($index = 0; $index < $granted; $index++) {
			$actions[] = ['actor' => 'a' . $index, 'state' => 'granted'];
		}

		for ($index = 0; $index < $refused; $index++) {
			$actions[] = ['actor' => 'r' . $index, 'state' => 'refused'];
		}

		return $actions;
	}//end actions()

	/**
	 * A step with no threshold at all means every approver, which is what every
	 * stored route means today. Nothing changes meaning.
	 *
	 * @return void
	 */
	public function testAStepWithNoThresholdNeedsEveryApprover(): void {
		self::assertSame(5, $this->calculator->required([], 5));
	}//end testAStepWithNoThresholdNeedsEveryApprover()

	/**
	 * A share rounds UP. Three of five at 0.6 is exactly three; at 0.5 it is
	 * three as well, because half of five is not a number of people.
	 *
	 * @return void
	 */
	public function testAShareRoundsUpToWholePeople(): void {
		self::assertSame(3, $this->calculator->required(['thresholdKind' => 'share', 'thresholdValue' => 0.6], 5));
		self::assertSame(3, $this->calculator->required(['thresholdKind' => 'share', 'thresholdValue' => 0.5], 5));
		self::assertSame(4, $this->calculator->required(['thresholdKind' => 'share', 'thresholdValue' => 0.8], 5));
	}//end testAShareRoundsUpToWholePeople()

	/**
	 * A count of more than everybody is capped, rather than leaving a step that
	 * can never close.
	 *
	 * @return void
	 */
	public function testACountOfMoreThanEverybodyIsCapped(): void {
		self::assertSame(5, $this->calculator->required(['thresholdKind' => 'count', 'thresholdValue' => 9], 5));
	}//end testACountOfMoreThanEverybodyIsCapped()

	/**
	 * A threshold kind the engine does not know is refused rather than silently
	 * treated as `all`.
	 *
	 * @return void
	 */
	public function testAnUnknownThresholdKindIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('all, count, share');

		$this->calculator->required(['thresholdKind' => 'majority'], 5);
	}//end testAnUnknownThresholdKindIsRefused()

	/**
	 * A share or count threshold with no value is refused: without one the step
	 * could never close and would look like a route that stalled.
	 *
	 * @return void
	 */
	public function testAThresholdWithNoValueIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('thresholdValue');

		$this->calculator->required(['thresholdKind' => 'share'], 5);
	}//end testAThresholdWithNoValueIsRefused()

	/**
	 * A share outside nought to one is refused.
	 *
	 * @return void
	 */
	public function testAShareOutsideItsRangeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->calculator->required(['thresholdKind' => 'share', 'thresholdValue' => 1.5], 5);
	}//end testAShareOutsideItsRangeIsRefused()

	/**
	 * Three grants of five at 0.8 is not enough, so the step is open.
	 *
	 * @return void
	 */
	public function testThreeOfFiveAtEightTenthsIsStillOpen(): void {
		$outcome = $this->calculator->outcome(
			['thresholdKind' => 'share', 'thresholdValue' => 0.8],
			$this->actions(3),
			5
		);

		self::assertSame(ApprovalThresholdCalculator::OUTCOME_OPEN, $outcome['outcome']);
		self::assertSame(4, $outcome['required']);
	}//end testThreeOfFiveAtEightTenthsIsStillOpen()

	/**
	 * Lowering the share closes the step, on the SAME three grants. Nobody is
	 * asked to approve anything again, which is only possible because the
	 * outcome is computed rather than stored (REQ-DWP-002).
	 *
	 * @return void
	 */
	public function testLoweringTheShareClosesAnOpenStep(): void {
		$actions = $this->actions(3);

		$before = $this->calculator->outcome(['thresholdKind' => 'share', 'thresholdValue' => 0.8], $actions, 5);
		$after = $this->calculator->outcome(['thresholdKind' => 'share', 'thresholdValue' => 0.6], $actions, 5);

		self::assertSame(ApprovalThresholdCalculator::OUTCOME_OPEN, $before['outcome']);
		self::assertSame(ApprovalThresholdCalculator::OUTCOME_GRANTED, $after['outcome']);
	}//end testLoweringTheShareClosesAnOpenStep()

	/**
	 * And raising it reopens a closed one, again on the same three grants.
	 *
	 * @return void
	 */
	public function testRaisingTheShareReopensAClosedStep(): void {
		$actions = $this->actions(3);

		$before = $this->calculator->outcome(['thresholdKind' => 'share', 'thresholdValue' => 0.5], $actions, 5);
		$after = $this->calculator->outcome(['thresholdKind' => 'share', 'thresholdValue' => 0.8], $actions, 5);

		self::assertSame(ApprovalThresholdCalculator::OUTCOME_GRANTED, $before['outcome']);
		self::assertSame(ApprovalThresholdCalculator::OUTCOME_OPEN, $after['outcome']);
	}//end testRaisingTheShareReopensAClosedStep()

	/**
	 * Enough refusals decide the step now, rather than leaving it open while
	 * people are chased for grants that can no longer change the answer.
	 *
	 * @return void
	 */
	public function testEnoughRefusalsCloseTheStepAsRefused(): void {
		$outcome = $this->calculator->outcome(
			['thresholdKind' => 'share', 'thresholdValue' => 0.6],
			$this->actions(0, 3),
			5
		);

		self::assertSame(ApprovalThresholdCalculator::OUTCOME_REFUSED, $outcome['outcome']);
	}//end testEnoughRefusalsCloseTheStepAsRefused()

	/**
	 * Two refusals of five at 0.6 still leave three possible grants, so the step
	 * stays open. The boundary matters: one refusal too eagerly counted would
	 * refuse steps that could still succeed.
	 *
	 * @return void
	 */
	public function testRefusalsThatStillLeaveEnoughApproversKeepTheStepOpen(): void {
		$outcome = $this->calculator->outcome(
			['thresholdKind' => 'share', 'thresholdValue' => 0.6],
			$this->actions(0, 2),
			5
		);

		self::assertSame(ApprovalThresholdCalculator::OUTCOME_OPEN, $outcome['outcome']);
	}//end testRefusalsThatStillLeaveEnoughApproversKeepTheStepOpen()

	/**
	 * A WITHDRAWN grant does not count. That is the point of withdrawing it: the
	 * step has to be able to reopen (REQ-DWP-003).
	 *
	 * @return void
	 */
	public function testAWithdrawnGrantDoesNotCount(): void {
		$actions = $this->actions(3);
		$actions[0]['state'] = 'withdrawn';

		$outcome = $this->calculator->outcome(['thresholdKind' => 'count', 'thresholdValue' => 3], $actions, 5);

		self::assertSame(2, $outcome['granted']);
		self::assertSame(ApprovalThresholdCalculator::OUTCOME_OPEN, $outcome['outcome']);
	}//end testAWithdrawnGrantDoesNotCount()

	/**
	 * The legacy verbs this engine already records count as grants, so a route in
	 * flight does not lose its approvals the day this ships.
	 *
	 * @return void
	 */
	public function testTheExistingApprovalVerbsCountAsGrants(): void {
		$outcome = $this->calculator->outcome(
			['thresholdKind' => 'count', 'thresholdValue' => 2],
			[['actor' => 'a', 'action' => 'approved'], ['actor' => 'b', 'action' => 'endorsed']],
			2
		);

		self::assertSame(ApprovalThresholdCalculator::OUTCOME_GRANTED, $outcome['outcome']);
	}//end testTheExistingApprovalVerbsCountAsGrants()

	/**
	 * The recomputation is recorded as an action carrying both thresholds and the
	 * actor. A step whose outcome changed with no action to point at is a step
	 * nobody can explain (REQ-DWP-002).
	 *
	 * @return void
	 */
	public function testTheThresholdChangeIsRecordedWithBothValuesAndTheActor(): void {
		$action = $this->calculator->recomputationAction(
			['order' => 2],
			0.8,
			0.6,
			'beheerder',
			'2026-09-18T12:00:00+00:00'
		);

		self::assertSame(2, $action['step']);
		self::assertSame(0.8, $action['thresholdBefore']);
		self::assertSame(0.6, $action['thresholdAfter']);
		self::assertSame('beheerder', $action['actor']);
		self::assertSame('threshold-changed', $action['action']);
	}//end testTheThresholdChangeIsRecordedWithBothValuesAndTheActor()

	/**
	 * A threshold change with nobody named is refused.
	 *
	 * @return void
	 */
	public function testAThresholdChangeNeedsAnActor(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('actor');

		$this->calculator->recomputationAction(['order' => 1], 0.8, 0.6, '  ');
	}//end testAThresholdChangeNeedsAnActor()
}//end class
