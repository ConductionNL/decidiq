<?php

/**
 * Unit tests for the admissibility verdict, the withdrawal, the remedy clause
 * and the open-approval guard.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001, REQ-DWP-004, REQ-DWP-005, REQ-DWP-007)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\AdmissibilityVerdictService;
use OCA\Decidiq\Service\DecisionWithdrawalService;
use OCA\Decidiq\Service\LegalRemedyResolver;
use OCA\Decidiq\Service\OpenApprovalGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Four small rules, each covering what happens and what is refused.
 */
final class DecisionWalkedProcessTest extends TestCase {
	/**
	 * An intake step.
	 *
	 * @return array<string, mixed> The step.
	 */
	private function intakeStep(): array {
		return ['order' => 1, 'stepKind' => 'intake'];
	}//end intakeStep()

	/**
	 * A decision type declaring bezwaar, six weeks, at the college.
	 *
	 * @return array<string, mixed> The type.
	 */
	private function typeWithBezwaar(): array {
		return [
			'name' => 'Omgevingsvergunning',
			'legalRemedies' => [
				['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college van burgemeester en wethouders'],
			],
		];
	}//end typeWithBezwaar()

	/**
	 * An inadmissible request ends the route and instantiates nothing further
	 * (REQ-DWP-004).
	 *
	 * @return void
	 */
	public function testAnInadmissibleRequestEndsTheRoute(): void {
		$verdict = (new AdmissibilityVerdictService())->record(
			$this->intakeStep(),
			AdmissibilityVerdictService::NIET_ONTVANKELIJK,
			'Artikel 4:5 Awb, aanvraag onvolledig na hersteltermijn',
			'j.jansen'
		);

		self::assertSame(AdmissibilityVerdictService::OUTCOME_ENDED_AT_INTAKE, $verdict['routeOutcome']);
		self::assertFalse($verdict['advances']);
	}//end testAnInadmissibleRequestEndsTheRoute()

	/**
	 * An admissible one walks on, and closes nothing.
	 *
	 * @return void
	 */
	public function testAnAdmissibleRequestWalksOn(): void {
		$verdict = (new AdmissibilityVerdictService())->record(
			$this->intakeStep(),
			AdmissibilityVerdictService::ONTVANKELIJK,
			'',
			'j.jansen'
		);

		self::assertNull($verdict['routeOutcome']);
		self::assertTrue($verdict['advances']);
	}//end testAnAdmissibleRequestWalksOn()

	/**
	 * A refusal with no ground is refused at the point of recording. By review
	 * time the term to object is already running against a refusal nobody can
	 * argue with (REQ-DWP-004).
	 *
	 * @return void
	 */
	public function testAnInadmissibleVerdictWithNoGroundIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('ground');

		(new AdmissibilityVerdictService())->record(
			$this->intakeStep(),
			AdmissibilityVerdictService::NIET_ONTVANKELIJK,
			'   ',
			'j.jansen'
		);
	}//end testAnInadmissibleVerdictWithNoGroundIsRefused()

	/**
	 * A verdict recorded on an approval step is refused: that step expects a
	 * grant, and a verdict there would close nothing and be invisible.
	 *
	 * @return void
	 */
	public function testAVerdictOnAnApprovalStepIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('intake step');

		(new AdmissibilityVerdictService())->record(
			['order' => 2, 'stepKind' => 'approval'],
			AdmissibilityVerdictService::ONTVANKELIJK,
			'',
			'j.jansen'
		);
	}//end testAVerdictOnAnApprovalStepIsRefused()

	/**
	 * The verdict is readable by the consuming case app, and a decision whose
	 * route had no intake step reads as no verdict rather than as an empty one.
	 *
	 * @return void
	 */
	public function testTheVerdictIsReadableAndAbsenceReadsAsNull(): void {
		$service = new AdmissibilityVerdictService();

		$read = $service->readFrom(
			[
				'ontvankelijkheid' => 'niet-ontvankelijk',
				'ontvankelijkheidGround' => 'Artikel 4:5 Awb',
				'ontvankelijkheidDecidedBy' => 'j.jansen',
			]
		);

		self::assertSame('niet-ontvankelijk', $read['ontvankelijkheid']);
		self::assertSame('Artikel 4:5 Awb', $read['ground']);
		self::assertNull($service->readFrom(['outcome' => 'granted']));
	}//end testTheVerdictIsReadableAndAbsenceReadsAsNull()

	/**
	 * The interested party withdraws their own request: the decision reads as
	 * withdrawn, names the party, and STILL shows what it originally decided
	 * (REQ-DWP-005).
	 *
	 * @return void
	 */
	public function testAWithdrawnDecisionStillShowsItsOriginalOutcome(): void {
		$service = new DecisionWithdrawalService();

		$withdrawn = $service->withdraw(
			['outcome' => 'granted', 'title' => 'Vergunning verleend'],
			DecisionWithdrawalService::BY_BELANGHEBBENDE,
			'Aanvraag ingetrokken door aanvrager',
			'2026-10-02T14:00:00+00:00'
		);

		$read = $service->readFrom($withdrawn);

		self::assertTrue($read['withdrawn']);
		self::assertSame('belanghebbende', $read['withdrawnBy']);

		// The fact somebody relied on, still there.
		self::assertSame('granted', $read['outcome']);
	}//end testAWithdrawnDecisionStillShowsItsOriginalOutcome()

	/**
	 * The withdrawal is appended to the history with the outcome as it stood, so
	 * the record can answer what people were entitled to rely on and until when.
	 *
	 * @return void
	 */
	public function testTheWithdrawalIsAppendedToTheHistory(): void {
		$withdrawn = (new DecisionWithdrawalService())->withdraw(
			['outcome' => 'granted', 'history' => [['event' => 'taken', 'at' => '2026-09-01T10:00:00+00:00']]],
			DecisionWithdrawalService::BY_BESTUURSORGAAN,
			'Nieuwe feiten',
			'2026-10-02T14:00:00+00:00'
		);

		self::assertCount(2, $withdrawn['history']);
		self::assertSame('withdrawn', $withdrawn['history'][1]['event']);
		self::assertSame('granted', $withdrawn['history'][1]['outcomeAtWithdrawal']);
	}//end testTheWithdrawalIsAppendedToTheHistory()

	/**
	 * A withdrawal with no actor kind is refused. The two kinds are different
	 * acts with different consequences (REQ-DWP-005).
	 *
	 * @return void
	 */
	public function testAWithdrawalWithNoActorKindIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('bestuursorgaan or belanghebbende');

		(new DecisionWithdrawalService())->withdraw(['outcome' => 'granted'], '');
	}//end testAWithdrawalWithNoActorKindIsRefused()

	/**
	 * A decision that was never taken cannot be withdrawn.
	 *
	 * @return void
	 */
	public function testADecisionThatWasNeverTakenCannotBeWithdrawn(): void {
		$this->expectException(InvalidArgumentException::class);

		(new DecisionWithdrawalService())->withdraw([], DecisionWithdrawalService::BY_BESTUURSORGAAN);
	}//end testADecisionThatWasNeverTakenCannotBeWithdrawn()

	/**
	 * Withdrawing twice is refused rather than appending a second withdrawal
	 * nobody can tell from the first.
	 *
	 * @return void
	 */
	public function testADecisionIsNotWithdrawnTwice(): void {
		$service = new DecisionWithdrawalService();
		$once = $service->withdraw(['outcome' => 'granted'], DecisionWithdrawalService::BY_BESTUURSORGAAN);

		$this->expectException(InvalidArgumentException::class);

		$service->withdraw($once, DecisionWithdrawalService::BY_BESTUURSORGAAN);
	}//end testADecisionIsNotWithdrawnTwice()

	/**
	 * A decision prints the clause its type declares, composed in weeks because
	 * that is how the term is given and how the reader counts (REQ-DWP-007).
	 *
	 * @return void
	 */
	public function testADecisionPrintsTheClauseItsTypeDeclares(): void {
		$clause = (new LegalRemedyResolver())->resolve($this->typeWithBezwaar());

		self::assertSame('bezwaar', $clause['kind']);
		self::assertSame(42, $clause['termDays']);
		self::assertStringContainsString('zes weken', $clause['text']);
		self::assertStringContainsString('het college van burgemeester en wethouders', $clause['text']);
	}//end testADecisionPrintsTheClauseItsTypeDeclares()

	/**
	 * A type that writes its own sentence keeps it.
	 *
	 * @return void
	 */
	public function testATypeThatWritesItsOwnSentenceKeepsIt(): void {
		$type = $this->typeWithBezwaar();
		$type['legalRemedies'][0]['text'] = 'U kunt bezwaar maken. Zie de bijlage.';

		self::assertSame(
			'U kunt bezwaar maken. Zie de bijlage.',
			(new LegalRemedyResolver())->resolve($type)['text']
		);
	}//end testATypeThatWritesItsOwnSentenceKeepsIt()

	/**
	 * A type declaring `geen` is a legitimate declaration and needs no term or
	 * body. That is what makes refusing an UNSET declaration safe to enforce.
	 *
	 * @return void
	 */
	public function testATypeDeclaringNoRemedyIsPublishable(): void {
		$clause = (new LegalRemedyResolver())->resolve(['legalRemedies' => [['kind' => 'geen']]]);

		self::assertSame('geen', $clause['kind']);
		self::assertStringContainsString('geen bezwaar of beroep', $clause['text']);
	}//end testATypeDeclaringNoRemedyIsPublishable()

	/**
	 * A type with no declaration cannot be published. A decision that tells
	 * nobody how to contest it looks complete and is sent, and the omission
	 * surfaces when somebody's term has already expired (REQ-DWP-007).
	 *
	 * @return void
	 */
	public function testATypeWithNoDeclarationCannotBePublished(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('does not declare its legal remedies');

		(new LegalRemedyResolver())->assertPublishable(['name' => 'Omgevingsvergunning']);
	}//end testATypeWithNoDeclarationCannotBePublished()

	/**
	 * A remedy with no term is refused: a remedy nobody can act on in time is
	 * not a remedy.
	 *
	 * @return void
	 */
	public function testARemedyWithNoTermIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('term in days');

		(new LegalRemedyResolver())->assertPublishable(
			['legalRemedies' => [['kind' => 'bezwaar', 'body' => 'het college']]]
		);
	}//end testARemedyWithNoTermIsRefused()

	/**
	 * The clause is STAMPED once and never re-resolved. A type whose term changes
	 * next year must not silently change what this decision told somebody last
	 * year (REQ-DWP-007).
	 *
	 * @return void
	 */
	public function testAStampedClauseIsNeverRewritten(): void {
		$resolver = new LegalRemedyResolver();
		$decision = $resolver->stamp(['outcome' => 'granted'], $this->typeWithBezwaar());

		$changedType = $this->typeWithBezwaar();
		$changedType['legalRemedies'][0]['termDays'] = 14;

		$again = $resolver->stamp($decision, $changedType);

		self::assertSame(42, $again['legalRemedyClause']['termDays']);
	}//end testAStampedClauseIsNeverRewritten()

	/**
	 * An open mandatory approval blocks the subject, and the refusal NAMES the
	 * approval and its assignee rather than only saying it is blocked
	 * (REQ-DWP-001).
	 *
	 * @return void
	 */
	public function testAnOpenApprovalBlocksAndTheRefusalNamesIt(): void {
		$guard = new OpenApprovalGuard();

		try {
			$guard->assertCanAdvance(
				['order' => 2, 'mandatory' => true],
				[['step' => 2, 'state' => 'open', 'assignee' => 'j.jansen']]
			);
			self::fail('The guard let a subject past an open mandatory approval.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('j.jansen', $e->getMessage());
			self::assertStringContainsString('step 2', $e->getMessage());
		}
	}//end testAnOpenApprovalBlocksAndTheRefusalNamesIt()

	/**
	 * A closed approval allows the advance.
	 *
	 * @return void
	 */
	public function testAClosedApprovalAllowsTheAdvance(): void {
		(new OpenApprovalGuard())->assertCanAdvance(
			['order' => 2, 'mandatory' => true],
			[['step' => 2, 'state' => 'granted', 'assignee' => 'j.jansen']]
		);

		self::assertTrue(true);
	}//end testAClosedApprovalAllowsTheAdvance()

	/**
	 * An UNASSIGNED open action still blocks, and says so. An approval nobody has
	 * been asked for is how a route sits still for a week with nothing to show.
	 *
	 * @return void
	 */
	public function testAnUnassignedOpenApprovalStillBlocksAndSaysSo(): void {
		$guard = new OpenApprovalGuard();

		try {
			$guard->assertCanAdvance(['order' => 2, 'mandatory' => true], [['step' => 2, 'state' => 'open']]);
			self::fail('The guard treated an unassigned approval as absent.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('not been assigned', $e->getMessage());
		}
	}//end testAnUnassignedOpenApprovalStillBlocksAndSaysSo()

	/**
	 * An open action on an OPTIONAL step does not block: skipping it is already
	 * allowed, so it is a suggestion rather than a gate.
	 *
	 * @return void
	 */
	public function testAnOpenApprovalOnAnOptionalStepDoesNotBlock(): void {
		$guard = new OpenApprovalGuard();

		self::assertFalse(
			$guard->isBlocked(['order' => 3, 'mandatory' => false], [['step' => 3, 'state' => 'open', 'assignee' => 'x']])
		);
	}//end testAnOpenApprovalOnAnOptionalStepDoesNotBlock()

	/**
	 * The assignee's task list carries the subject, the step and the due date,
	 * soonest first, with the undated last (REQ-DWP-001).
	 *
	 * @return void
	 */
	public function testTheTaskListIsTheirsAndSoonestFirst(): void {
		$tasks = (new OpenApprovalGuard())->taskListFor(
			'j.jansen',
			[
				['state' => 'open', 'assignee' => 'j.jansen', 'subject' => 'later', 'step' => 2, 'dueAt' => '2026-10-01'],
				['state' => 'open', 'assignee' => 'p.peters', 'subject' => 'not-mine', 'step' => 1, 'dueAt' => '2026-09-01'],
				['state' => 'open', 'assignee' => 'j.jansen', 'subject' => 'undated', 'step' => 3],
				['state' => 'open', 'assignee' => 'j.jansen', 'subject' => 'sooner', 'step' => 1, 'dueAt' => '2026-09-20'],
				['state' => 'granted', 'assignee' => 'j.jansen', 'subject' => 'done', 'step' => 1],
			]
		);

		self::assertSame(
			['sooner', 'later', 'undated'],
			array_map(static fn (array $task): string => (string)$task['subject'], $tasks)
		);
		self::assertSame(1, $tasks[0]['step']);
	}//end testTheTaskListIsTheirsAndSoonestFirst()
}//end class
