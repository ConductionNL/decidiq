<?php

/**
 * Unit tests for ApprovalBasisWatcher.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalBasisWatcher;
use PHPUnit\Framework\TestCase;

/**
 * Covers what withdraws an approval, what deliberately does not, and who is
 * told when one is taken away.
 */
final class ApprovalBasisWatcherTest extends TestCase {
	/**
	 * The watcher under test.
	 *
	 * @var ApprovalBasisWatcher
	 */
	private ApprovalBasisWatcher $watcher;

	/**
	 * Build the watcher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->watcher = new ApprovalBasisWatcher();
	}//end setUp()

	/**
	 * A step whose approvers are approving the body and the attachments.
	 *
	 * @return array<string, mixed> The step.
	 */
	private function step(): array {
		return ['order' => 2, 'approvalBasis' => ['body', 'attachments']];
	}//end step()

	/**
	 * One standing grant.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function grant(): array {
		return [['actor' => 'j.jansen', 'state' => 'granted']];
	}//end grant()

	/**
	 * The subject as it stood when the approval was given.
	 *
	 * @return array<string, mixed> The subject.
	 */
	private function subject(): array {
		return ['body' => 'De oorspronkelijke tekst.', 'attachments' => ['a.pdf'], 'internalNote' => 'nog checken'];
	}//end subject()

	/**
	 * Editing what was approved withdraws the approval and reopens the step.
	 *
	 * @return void
	 */
	public function testEditingWhatWasApprovedWithdrawsTheApproval(): void {
		$after = $this->subject();
		$after['body'] = 'Een heel andere tekst.';

		$withdrawals = $this->watcher->withdrawals($this->step(), $this->grant(), $this->subject(), $after, '2026-09-20T09:00:00+00:00');

		self::assertCount(1, $withdrawals);
		self::assertSame('withdrawn', $withdrawals[0]['state']);
		self::assertSame(ApprovalBasisWatcher::REASON_BASIS_CHANGED, $withdrawals[0]['withdrawnReason']);
		self::assertSame(['body'], $withdrawals[0]['withdrawnPaths']);
		self::assertTrue($this->watcher->reopens($this->step(), $this->grant(), $this->subject(), $after));
	}//end testEditingWhatWasApprovedWithdrawsTheApproval()

	/**
	 * Editing OUTSIDE the basis keeps the approval. Without this the feature
	 * would throw away every approval on every routine edit and be switched off
	 * by the first team it happened to.
	 *
	 * @return void
	 */
	public function testEditingOutsideTheBasisKeepsTheApproval(): void {
		$after = $this->subject();
		$after['internalNote'] = 'gecheckt';

		self::assertSame([], $this->watcher->withdrawals($this->step(), $this->grant(), $this->subject(), $after));
		self::assertFalse($this->watcher->reopens($this->step(), $this->grant(), $this->subject(), $after));
	}//end testEditingOutsideTheBasisKeepsTheApproval()

	/**
	 * An EMPTY basis never withdraws anything, which is what makes this opt-in.
	 *
	 * @return void
	 */
	public function testAnEmptyBasisNeverWithdrawsAnything(): void {
		$after = $this->subject();
		$after['body'] = 'Een heel andere tekst.';

		self::assertSame([], $this->watcher->withdrawals(['order' => 2], $this->grant(), $this->subject(), $after));
		self::assertSame([], $this->watcher->withdrawals(['order' => 2, 'approvalBasis' => []], $this->grant(), $this->subject(), $after));
	}//end testAnEmptyBasisNeverWithdrawsAnything()

	/**
	 * A save that writes the same value back is not a change to what was
	 * approved, whatever the update event says. Comparing values rather than
	 * trusting a change feed is what makes the rule trustworthy.
	 *
	 * @return void
	 */
	public function testASaveThatChangesNothingWithdrawsNothing(): void {
		self::assertSame([], $this->watcher->withdrawals($this->step(), $this->grant(), $this->subject(), $this->subject()));
	}//end testASaveThatChangesNothingWithdrawsNothing()

	/**
	 * A change to a nested path inside the basis counts.
	 *
	 * @return void
	 */
	public function testANestedPathInsideTheBasisCounts(): void {
		$step = ['approvalBasis' => ['budget.amount']];
		$before = ['budget' => ['amount' => 1000, 'note' => 'x']];
		$after = ['budget' => ['amount' => 2500, 'note' => 'x']];

		self::assertSame(['budget.amount'], $this->watcher->changedPaths($step, $before, $after));
	}//end testANestedPathInsideTheBasisCounts()

	/**
	 * A sibling of a nested basis path does not count.
	 *
	 * @return void
	 */
	public function testASiblingOfANestedPathDoesNotCount(): void {
		$step = ['approvalBasis' => ['budget.amount']];
		$before = ['budget' => ['amount' => 1000, 'note' => 'x']];
		$after = ['budget' => ['amount' => 1000, 'note' => 'y']];

		self::assertSame([], $this->watcher->changedPaths($step, $before, $after));
	}//end testASiblingOfANestedPathDoesNotCount()

	/**
	 * A basis property that appears where there was none counts as a change: an
	 * attachment added to a document somebody approved is exactly the case this
	 * rule is for.
	 *
	 * @return void
	 */
	public function testAPropertyAppearingWhereThereWasNoneCounts(): void {
		$before = ['body' => 'x'];
		$after = ['body' => 'x', 'attachments' => ['nieuw.pdf']];

		self::assertSame(['attachments'], $this->watcher->changedPaths($this->step(), $before, $after));
	}//end testAPropertyAppearingWhereThereWasNoneCounts()

	/**
	 * A REFUSAL is not withdrawn by an edit. Somebody who said no to an earlier
	 * version has not thereby said nothing, and asking them again is a decision
	 * for a human.
	 *
	 * @return void
	 */
	public function testARefusalIsNotWithdrawnByAnEdit(): void {
		$after = $this->subject();
		$after['body'] = 'anders';

		$withdrawals = $this->watcher->withdrawals(
			$this->step(),
			[['actor' => 'j.jansen', 'state' => 'refused']],
			$this->subject(),
			$after
		);

		self::assertSame([], $withdrawals);
	}//end testARefusalIsNotWithdrawnByAnEdit()

	/**
	 * An already-withdrawn grant is not withdrawn twice.
	 *
	 * @return void
	 */
	public function testAnAlreadyWithdrawnGrantIsNotWithdrawnAgain(): void {
		$after = $this->subject();
		$after['body'] = 'anders';

		$withdrawals = $this->watcher->withdrawals(
			$this->step(),
			[['actor' => 'j.jansen', 'state' => 'withdrawn']],
			$this->subject(),
			$after
		);

		self::assertSame([], $withdrawals);
	}//end testAnAlreadyWithdrawnGrantIsNotWithdrawnAgain()

	/**
	 * Every approver whose grant was taken away is named once, so each can be
	 * told. An approver who is not notified finds out when somebody asks them why
	 * the step is still open, which is the worst possible moment.
	 *
	 * @return void
	 */
	public function testEveryApproverWhoseGrantWasTakenAwayIsNamedOnce(): void {
		$after = $this->subject();
		$after['body'] = 'anders';

		$withdrawals = $this->watcher->withdrawals(
			$this->step(),
			[
				['actor' => 'j.jansen', 'state' => 'granted'],
				['actor' => 'p.peters', 'state' => 'granted'],
				['actor' => 'j.jansen', 'state' => 'granted'],
			],
			$this->subject(),
			$after
		);

		self::assertSame(['j.jansen', 'p.peters'], $this->watcher->actorsToNotify($withdrawals));
	}//end testEveryApproverWhoseGrantWasTakenAwayIsNamedOnce()

	/**
	 * The legacy approval verbs are grants too, so a route in flight loses its
	 * approvals correctly rather than not at all.
	 *
	 * @return void
	 */
	public function testTheExistingApprovalVerbsAreWithdrawnToo(): void {
		$after = $this->subject();
		$after['body'] = 'anders';

		$withdrawals = $this->watcher->withdrawals(
			$this->step(),
			[['actor' => 'j.jansen', 'action' => 'endorsed']],
			$this->subject(),
			$after
		);

		self::assertCount(1, $withdrawals);
	}//end testTheExistingApprovalVerbsAreWithdrawnToo()
}//end class
