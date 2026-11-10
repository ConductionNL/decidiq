<?php

/**
 * Golden test for PlanningCycleGenerator::stepsFor.
 *
 * Pins every step payload a template gives: skipped entries, the label
 * falling back to the step type, the year offset, the optional step type,
 * dates resolved, unreadable and impossible, document slots, and the order
 * of the payload's keys.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PlanningCycleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the step payloads of a planning cycle.
 */
final class PlanningCycleStepsGoldenTest extends TestCase {
	/**
	 * Every kind of template entry, in one template.
	 *
	 * @return void
	 */
	public function testEveryStepPayload(): void {
		$template = [
			'steps' => [
				'not a step',
				['stepType' => ' kadernota ', 'label' => ' Kadernota ', 'subjectYearOffset' => 1, 'deliveryDeadline' => '03-15', 'committeeDate' => '02-30', 'handlingDate' => 'soon', 'documentSlots' => [['name' => ' Nota ', 'required' => 1], ['name' => ' '], 'x', ['name' => 'Bijlage']]],
				['stepType' => 'decharge'],
				['label' => '  ', 'stepType' => ''],
				['label' => 'Voorjaarsnota', 'technicalQuestionsStart' => '4-1', 'technicalQuestionsEnd' => 5, 'subjectYearOffset' => '-1', 'documentSlots' => 'none'],
				['label' => 'Leap', 'handlingDate' => '02-29'],
			],
		];

		$this->assertSame(
			[
				['cycle' => 'c-1', 'sequence' => 2, 'label' => 'Kadernota', 'status' => 'planned', 'concernsYear' => 2029, 'stepType' => 'kadernota', 'deliveryDeadline' => '2028-03-15', 'documentSlots' => [['name' => 'Nota', 'required' => true], ['name' => 'Bijlage', 'required' => false]]],
				['cycle' => 'c-1', 'sequence' => 3, 'label' => 'decharge', 'status' => 'planned', 'concernsYear' => 2028, 'stepType' => 'decharge'],
				['cycle' => 'c-1', 'sequence' => 5, 'label' => 'Voorjaarsnota', 'status' => 'planned', 'concernsYear' => 2027, 'technicalQuestionsStart' => '2028-04-01'],
				['cycle' => 'c-1', 'sequence' => 6, 'label' => 'Leap', 'status' => 'planned', 'concernsYear' => 2028, 'handlingDate' => '2028-02-29'],
			],
			(new PlanningCycleGenerator())->stepsFor(cycleId: 'c-1', year: 2028, template: $template)
		);
	}//end testEveryStepPayload()

	/**
	 * A template without steps, or with a non-list, gives none.
	 *
	 * @return void
	 */
	public function testNoSteps(): void {
		$generator = new PlanningCycleGenerator();
		$this->assertSame([], $generator->stepsFor(cycleId: 'c', year: 2027, template: []));
		$this->assertSame([], $generator->stepsFor(cycleId: 'c', year: 2027, template: ['steps' => null]));
	}//end testNoSteps()
}//end class
