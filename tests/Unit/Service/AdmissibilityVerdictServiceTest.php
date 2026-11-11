<?php

/**
 * Characterisation tests for recording an admissibility verdict: every refusal
 * with its message, and the shape of the recorded verdict.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\AdmissibilityVerdictService;
use OCA\Decidiq\Service\IntakeVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Pins AdmissibilityVerdictService::record branch by branch.
 */
final class AdmissibilityVerdictServiceTest extends TestCase {
	/**
	 * A step without a kind is an approval step and is refused.
	 *
	 * @return void
	 */
	public function testAStepWithoutAKindIsRefusedAsAnApprovalStep(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			'An admissibility verdict belongs on an intake step; this step is an approval step and expects a grant or a refusal.'
		);

		(new AdmissibilityVerdictService())->record(['order' => 1], new IntakeVerdict(verdict: AdmissibilityVerdictService::ONTVANKELIJK, ground: '', decidedBy: 'j.jansen'));
	}//end testAStepWithoutAKindIsRefusedAsAnApprovalStep()

	/**
	 * An unknown verdict is refused and the message lists the known ones.
	 *
	 * @return void
	 */
	public function testAnUnknownVerdictIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown verdict "maybe"; expected ontvankelijk or niet-ontvankelijk.');

		(new AdmissibilityVerdictService())->record(['stepKind' => 'intake'], new IntakeVerdict(verdict: 'maybe', ground: '', decidedBy: 'j.jansen'));
	}//end testAnUnknownVerdictIsRefused()

	/**
	 * The step kind is checked before the verdict.
	 *
	 * @return void
	 */
	public function testTheStepKindIsCheckedBeforeTheVerdict(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('intake step');

		(new AdmissibilityVerdictService())->record(['stepKind' => 'approval'], new IntakeVerdict(verdict: 'maybe', ground: '', decidedBy: ''));
	}//end testTheStepKindIsCheckedBeforeTheVerdict()

	/**
	 * A verdict that names nobody is refused, before the ground is checked.
	 *
	 * @return void
	 */
	public function testAVerdictWithoutADeciderIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A verdict has to name who gave it.');

		(new AdmissibilityVerdictService())->record(
			['stepKind' => 'intake'],
			new IntakeVerdict(verdict: AdmissibilityVerdictService::NIET_ONTVANKELIJK, ground: '', decidedBy: '  ')
		);
	}//end testAVerdictWithoutADeciderIsRefused()

	/**
	 * A refusal without a ground is refused with the full message.
	 *
	 * @return void
	 */
	public function testARefusalWithoutAGroundIsRefusedWithItsMessage(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			'A niet-ontvankelijk verdict needs the ground it rests on; without one the party has nothing to contest.'
		);

		(new AdmissibilityVerdictService())->record(
			['stepKind' => 'intake'],
			new IntakeVerdict(verdict: AdmissibilityVerdictService::NIET_ONTVANKELIJK, ground: '', decidedBy: 'j.jansen')
		);
	}//end testARefusalWithoutAGroundIsRefusedWithItsMessage()

	/**
	 * A refusal is recorded with its trimmed ground and the given instant.
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedInFull(): void {
		$verdict = (new AdmissibilityVerdictService())->record(
			['stepKind' => 'intake'],
			new IntakeVerdict(verdict: AdmissibilityVerdictService::NIET_ONTVANKELIJK, ground: '  Artikel 4:5 Awb  ', decidedBy: 'j.jansen', decidedAt: '2026-03-01T10:00:00+00:00')
		);

		self::assertSame(
			[
				'verdict' => 'niet-ontvankelijk',
				'ground' => 'Artikel 4:5 Awb',
				'decidedBy' => 'j.jansen',
				'decidedAt' => '2026-03-01T10:00:00+00:00',
				'routeOutcome' => 'ended-at-intake',
				'advances' => false,
			],
			$verdict
		);
	}//end testARefusalIsRecordedInFull()

	/**
	 * An admissible verdict with no instant is stamped now, as an ISO-8601 instant.
	 *
	 * @return void
	 */
	public function testAnAdmissibleVerdictWithoutAnInstantIsStampedNow(): void {
		$before = time();
		$verdict = (new AdmissibilityVerdictService())->record(
			['stepKind' => 'intake'],
			new IntakeVerdict(verdict: AdmissibilityVerdictService::ONTVANKELIJK, ground: ' note ', decidedBy: 'j.jansen')
		);
		$after = time();

		self::assertSame('note', $verdict['ground']);
		self::assertNull($verdict['routeOutcome']);
		self::assertTrue($verdict['advances']);
		$stamped = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $verdict['decidedAt']);
		self::assertNotFalse($stamped);
		self::assertGreaterThanOrEqual($before, $stamped->getTimestamp());
		self::assertLessThanOrEqual($after, $stamped->getTimestamp());
	}//end testAnAdmissibleVerdictWithoutAnInstantIsStampedNow()
}//end class
