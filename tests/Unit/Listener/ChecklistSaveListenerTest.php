<?php

/**
 * Tests for ChecklistSaveListener and ChecklistProgress.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\ChecklistSaveListener;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Service\ChecklistProgress;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * An onboarding or offboarding does not complete with a mandatory step open, does not leave a final state, and carries its next due step.
 *
 * @covers \OCA\Decidiq\Listener\ChecklistSaveListener
 * @covers \OCA\Decidiq\Service\ChecklistProgress
 *
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
 */
final class ChecklistSaveListenerTest extends TestCase {

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * An onboarding of a joining member: the oath is mandatory, the tour is not.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function onboarding(array $changes=[]): array {
		return $changes + [
			'person' => 'p-jansen',
			'lifecycle' => 'in-execution',
			'steps' => [
				['stepType' => 'installation', 'title' => 'Take the oath', 'status' => 'open', 'mandatory' => true, 'dueDate' => '2026-12-10'],
				['stepType' => 'custom', 'title' => 'Take the building tour', 'status' => 'open', 'mandatory' => false, 'dueDate' => '2026-12-01'],
				['stepType' => 'induction-pack', 'title' => 'Hand over the induction pack', 'status' => 'completed', 'dueDate' => '2026-11-01'],
			],
		];
	}//end onboarding()

	/**
	 * Completing with the oath still open is refused, naming the step; the optional tour does not block.
	 *
	 * @return void
	 */
	public function testAnOpenMandatoryStepKeepsTheRecordFromCompleting(): void {
		$completing = $this->entity(row: self::onboarding(changes: ['lifecycle' => 'completed']));
		$event      = new ObjectUpdatingEvent($completing, $this->entity(row: self::onboarding()));

		(new ChecklistSaveListener())->handle(event: $event);

		self::assertTrue(condition: $event->isPropagationStopped());
		self::assertSame(
			expected: 'This record cannot be completed while mandatory steps are open: Take the oath.',
			actual: $event->getErrors()['message']
		);
	}//end testAnOpenMandatoryStepKeepsTheRecordFromCompleting()

	/**
	 * With every mandatory step done the record completes, whatever the optional steps say.
	 *
	 * @return void
	 */
	public function testARecordWithItsMandatoryStepsDoneCompletes(): void {
		$record = self::onboarding(changes: ['lifecycle' => 'completed']);
		$record['steps'][0]['status'] = 'completed';

		self::assertNull(actual: (new ChecklistProgress())->refusal(record: $record, stored: self::onboarding()));
	}//end testARecordWithItsMandatoryStepsDoneCompletes()

	/**
	 * Taking access away is mandatory even when the step says it is not.
	 *
	 * @return void
	 */
	public function testRevokingAccessIsAlwaysMandatory(): void {
		$record = [
			'lifecycle' => 'completed',
			'steps' => [['stepType' => 'revoke-groups', 'title' => 'Revoke access', 'status' => 'skipped', 'mandatory' => false]],
		];

		self::assertSame(expected: ['Revoke access'], actual: (new ChecklistProgress())->openMandatory(record: $record));
	}//end testRevokingAccessIsAlwaysMandatory()

	/**
	 * A completed or lapsed record does not change state again; staying in it is fine.
	 *
	 * @return void
	 */
	public function testAFinalStateIsFinal(): void {
		$progress = new ChecklistProgress();
		$done     = self::onboarding(changes: ['lifecycle' => 'completed']);

		self::assertSame(
			expected: 'This record is completed and its state no longer changes.',
			actual: $progress->refusal(record: self::onboarding(changes: ['lifecycle' => 'started']), stored: $done)
		);
		self::assertSame(
			expected: 'This record is lapsed and its state no longer changes.',
			actual: $progress->refusal(record: self::onboarding(), stored: self::onboarding(changes: ['lifecycle' => 'lapsed']))
		);
		$noted = $done;
		$noted['steps'][0]['status'] = 'completed';
		self::assertNull(actual: $progress->refusal(record: $noted, stored: $noted));
	}//end testAFinalStateIsFinal()

	/**
	 * A save that may go ahead is stamped with the open step due first; completed steps do not count.
	 *
	 * @return void
	 */
	public function testTheOpenStepDueFirstIsStamped(): void {
		$event = new ObjectCreatingEvent($this->entity(row: self::onboarding()));

		(new ChecklistSaveListener())->handle(event: $event);

		self::assertFalse(condition: $event->isPropagationStopped());
		self::assertSame(
			expected: ['nextStepDueDate' => '2026-12-01', 'nextStepTitle' => 'Take the building tour'],
			actual: $event->getModifiedData()
		);
	}//end testTheOpenStepDueFirstIsStamped()

	/**
	 * Without an open step that has a due date, both fields are cleared.
	 *
	 * @return void
	 */
	public function testNothingDueClearsTheFields(): void {
		$record = self::onboarding();
		$record['steps'][0]['status'] = 'completed';
		unset($record['steps'][1]['dueDate']);

		self::assertSame(
			expected: ['nextStepDueDate' => null, 'nextStepTitle' => null],
			actual: (new ChecklistProgress())->nextDue(record: $record)
		);
	}//end testNothingDueClearsTheFields()

	/**
	 * The listener guards creates and updates of both checklist schemas, and the reminders read the stamped date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-010-declarative-step-reminders
	 */
	public function testItIsSubscribedAndTheRemindersReadTheStampedDate(): void {
		$guards = array_values(
			array_filter(SaveGuardSubscriptions::ALL, static fn (array $g): bool => $g['listener'] === ChecklistSaveListener::class)
		);
		self::assertSame(expected: [ObjectCreatingEvent::class, ObjectUpdatingEvent::class], actual: array_column($guards, 'event'));
		self::assertSame(expected: ['member-onboarding', 'member-offboarding'], actual: $guards[0]['schemas']);

		foreach (['member-onboarding' => 'onboarding', 'member-offboarding' => 'offboarding'] as $slug => $kind) {
			$schema = MergedRegisterSchema::forSlug(slug: $slug);
			self::assertTrue(condition: $schema['properties']['nextStepDueDate']['readOnly']);
			$rules = $schema['x-openregister-notifications'];
			$live  = ['operator' => 'in', 'values' => ['started', 'in-execution']];
			self::assertSame(
				expected: ['lifecycle' => $live, 'nextStepDueDate' => ['operator' => 'withinNext', 'value' => 'P7D']],
				actual: $rules[$kind.'StepDueSoon']['trigger']['filter']
			);
			self::assertSame(
				expected: ['lifecycle' => $live, 'nextStepDueDate' => ['operator' => 'before', 'value' => 'now']],
				actual: $rules[$kind.'StepOverdue']['trigger']['filter']
			);
			self::assertStringContainsString(needle: '{{nextStepTitle}}', haystack: $rules[$kind.'StepOverdue']['subject']['en']);
			self::assertStringContainsString(needle: '{{nextStepTitle}}', haystack: $rules[$kind.'StepOverdue']['subject']['nl']);
		}
	}//end testItIsSubscribedAndTheRemindersReadTheStampedDate()
}//end class
