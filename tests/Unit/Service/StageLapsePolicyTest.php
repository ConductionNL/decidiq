<?php

/**
 * Unit tests for StageLapsePolicy.
 *
 * What a step's silence means, on a fixed clock. The default is the most
 * important case in the file: every route written before this change has no
 * `onSilence`, and every one of them has to keep meaning nothing.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-016, REQ-AR-017)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\ApprovalActorResolver;
use OCA\Decidiq\Service\StageLapsePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the four declared meanings of silence, the substitute ask point, and
 * the stamp that makes the sweep safe to run twice.
 */
final class StageLapsePolicyTest extends TestCase {
	/**
	 * The policy under test.
	 *
	 * @var StageLapsePolicy
	 */
	private StageLapsePolicy $policy;

	/**
	 * The clock every test reads.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Build the policy and fix the clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->policy = new StageLapsePolicy();
		$this->now = new DateTimeImmutable('2026-09-18T12:00:00+00:00');
	}//end setUp()

	/**
	 * An active stage, overridable field by field.
	 *
	 * @param array<string, mixed> $overrides What this test cares about.
	 *
	 * @return array<string, mixed> The stage.
	 */
	private function stage(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'stage-1',
				'status' => 'active',
				'sequence' => 2,
				'decision' => 'subject-1',
				'note' => 'decision',
				'assignedPerson' => 'j.jansen',
				'activatedAt' => '2026-09-08T12:00:00+00:00',
				'dueAt' => '2026-09-16T12:00:00+00:00',
			],
			$overrides
		);
	}//end stage()

	/**
	 * No declaration, a term two days gone, and nothing happens.
	 *
	 * @return void
	 */
	public function testTheDefaultHolds(): void {
		$effect = $this->policy->effectFor(stage: $this->stage(), now: $this->now);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $effect['effect']);
		$this->assertNull($effect['outcome']);
	}//end testTheDefaultHolds()

	/**
	 * A declared approval advances.
	 *
	 * @return void
	 */
	public function testADeclaredApprovalAdvances(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage(['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_ADVANCE, $effect['effect']);
		$this->assertSame('approved', $effect['outcome']);
	}//end testADeclaredApprovalAdvances()

	/**
	 * A declared refusal concludes.
	 *
	 * @return void
	 */
	public function testADeclaredRefusalConcludes(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage(['onSilence' => StageLapsePolicy::ON_SILENCE_REFUSE]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_CONCLUDE, $effect['effect']);
		$this->assertSame('rejected', $effect['outcome']);
	}//end testADeclaredRefusalConcludes()

	/**
	 * Escalation happens once, and the second lapse holds.
	 *
	 * @return void
	 */
	public function testEscalationHappensOnce(): void {
		$first = $this->policy->effectFor(
			stage: $this->stage(['onSilence' => StageLapsePolicy::ON_SILENCE_ESCALATE]),
			now: $this->now
		);
		$this->assertSame(StageLapsePolicy::EFFECT_REASSIGN, $first['effect']);

		$second = $this->policy->effectFor(
			stage: $this->stage([
				'onSilence' => StageLapsePolicy::ON_SILENCE_ESCALATE,
				'assignedPerson' => 'd.devries',
				'actorResolvedBy' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
			]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $second['effect']);
		$this->assertStringContainsString('already been escalated once', $second['reason']);
	}//end testEscalationHappensOnce()

	/**
	 * A stage with no term never lapses, whatever it declares.
	 *
	 * @return void
	 */
	public function testAStageWithNoDueDateNeverLapses(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage(['onSilence' => StageLapsePolicy::ON_SILENCE_REFUSE, 'dueAt' => '']),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $effect['effect']);
		$this->assertStringContainsString('no due date', $effect['reason']);
	}//end testAStageWithNoDueDateNeverLapses()

	/**
	 * A term still running is not silence.
	 *
	 * @return void
	 */
	public function testAStageNotYetDueDoesNotLapse(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage([
				'onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE,
				'dueAt' => '2026-09-20T12:00:00+00:00',
			]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $effect['effect']);
	}//end testAStageNotYetDueDoesNotLapse()

	/**
	 * Somebody who signed just in time wins against the sweep.
	 *
	 * @return void
	 */
	public function testAStageAlreadyDecidedIsLeftAlone(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage([
				'status' => 'decided',
				'onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE,
			]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $effect['effect']);
		$this->assertStringContainsString('no longer active', $effect['reason']);
	}//end testAStageAlreadyDecidedIsLeftAlone()

	/**
	 * A stage that already lapsed is skipped, which is what lets the sweep be
	 * re-run after a failure.
	 *
	 * @return void
	 */
	public function testALapseIsAppliedOnlyOnce(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage([
				'onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE,
				'lapsedAt' => '2026-09-17T03:00:00+00:00',
			]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $effect['effect']);
		$this->assertStringContainsString('already been applied', $effect['reason']);
	}//end testALapseIsAppliedOnlyOnce()

	/**
	 * The action a lapse writes reads the STAGE's field names, and says which
	 * policy moved the step.
	 *
	 * @return void
	 */
	public function testTheLapseActionNamesTheSubjectTheStepAndThePolicy(): void {
		$stage = $this->stage(['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE]);
		$effect = $this->policy->effectFor(stage: $stage, now: $this->now);

		$action = $this->policy->lapseAction(stage: $stage, effect: $effect, now: $this->now);

		$this->assertSame('subject-1', $action['subject']);
		$this->assertSame('decision', $action['subjectSchema']);
		$this->assertSame(2, $action['step']);
		$this->assertSame('system', $action['actorType']);
		$this->assertSame('lapsed', $action['action']);
		$this->assertSame('granted', $action['state']);
		$this->assertSame(StageLapsePolicy::ON_SILENCE_APPROVE, $action['onSilencePolicy']);
		$this->assertSame('2026-09-18T12:00:00+00:00', $action['recordedAt']);
	}//end testTheLapseActionNamesTheSubjectTheStepAndThePolicy()

	/**
	 * A refusal on silence is recorded as refused, not as a grant.
	 *
	 * @return void
	 */
	public function testALapsedRefusalIsRecordedAsRefused(): void {
		$stage = $this->stage(['onSilence' => StageLapsePolicy::ON_SILENCE_REFUSE]);
		$effect = $this->policy->effectFor(stage: $stage, now: $this->now);

		$action = $this->policy->lapseAction(stage: $stage, effect: $effect, now: $this->now);

		$this->assertSame('refused', $action['state']);
	}//end testALapsedRefusalIsRecordedAsRefused()

	/**
	 * Only an administrator may declare that silence approves.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorMaySetApproveOnSilence(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Only an administrator');

		$this->policy->assertSettable(onSilence: StageLapsePolicy::ON_SILENCE_APPROVE, isAdministrator: false);
	}//end testOnlyAnAdministratorMaySetApproveOnSilence()

	/**
	 * The other three meanings need no administrator, and an unknown one is
	 * refused outright.
	 *
	 * @return void
	 */
	public function testTheOtherMeaningsAreSettableAndAnUnknownOneIsNot(): void {
		$this->policy->assertSettable(onSilence: StageLapsePolicy::ON_SILENCE_ESCALATE, isAdministrator: false);
		$this->policy->assertSettable(onSilence: StageLapsePolicy::ON_SILENCE_APPROVE, isAdministrator: true);
		$this->addToAssertionCount(2);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown onSilence');
		$this->policy->assertSettable(onSilence: 'ignore', isAdministrator: true);
	}//end testTheOtherMeaningsAreSettableAndAnUnknownOneIsNot()

	/**
	 * Half way through a ten day window, the stand-in is asked.
	 *
	 * @return void
	 */
	public function testTheSubstituteIsAskedAtTheDeclaredPoint(): void {
		$stage = $this->stage([
			'activatedAt' => '2026-09-13T12:00:00+00:00',
			'dueAt' => '2026-09-23T12:00:00+00:00',
			'askSubstituteAfter' => 0.5,
		]);

		$this->assertSame(
			'2026-09-18T12:00:00+00:00',
			$this->policy->askPointFor($stage)?->format(DateTimeImmutable::ATOM)
		);
		$this->assertTrue($this->policy->shouldAskSubstitute(stage: $stage, now: $this->now));
	}//end testTheSubstituteIsAskedAtTheDeclaredPoint()

	/**
	 * Before the point, and once it has been settled, nobody is asked again.
	 *
	 * @return void
	 */
	public function testTheSubstituteIsNotAskedEarlyNorTwice(): void {
		$early = $this->stage([
			'activatedAt' => '2026-09-17T12:00:00+00:00',
			'dueAt' => '2026-09-27T12:00:00+00:00',
			'askSubstituteAfter' => 0.5,
		]);
		$this->assertFalse($this->policy->shouldAskSubstitute(stage: $early, now: $this->now));

		$asked = $this->stage([
			'activatedAt' => '2026-09-13T12:00:00+00:00',
			'dueAt' => '2026-09-23T12:00:00+00:00',
			'askSubstituteAfter' => 0.5,
			'substituteAskedAt' => '2026-09-18T11:00:00+00:00',
		]);
		$this->assertFalse($this->policy->shouldAskSubstitute(stage: $asked, now: $this->now));

		$searched = $this->stage([
			'activatedAt' => '2026-09-13T12:00:00+00:00',
			'dueAt' => '2026-09-23T12:00:00+00:00',
			'askSubstituteAfter' => 0.5,
			'substituteSearchedAt' => '2026-09-18T11:00:00+00:00',
		]);
		$this->assertFalse($this->policy->shouldAskSubstitute(stage: $searched, now: $this->now));
	}//end testTheSubstituteIsNotAskedEarlyNorTwice()

	/**
	 * Unset means no stand-in is asked at all.
	 *
	 * @return void
	 */
	public function testAnUnsetAskPointAsksNobody(): void {
		$this->assertNull($this->policy->askPointFor($this->stage()));
		$this->assertFalse($this->policy->shouldAskSubstitute(stage: $this->stage(), now: $this->now));
	}//end testAnUnsetAskPointAsksNobody()

	/**
	 * An unparseable term does not take the sweep down with it.
	 *
	 * @return void
	 */
	public function testAnUnreadableDueDateDoesNotLapse(): void {
		$effect = $this->policy->effectFor(
			stage: $this->stage([
				'onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE,
				'dueAt' => 'whenever',
			]),
			now: $this->now
		);

		$this->assertSame(StageLapsePolicy::EFFECT_NONE, $effect['effect']);
	}//end testAnUnreadableDueDateDoesNotLapse()
}//end class
