<?php

/**
 * Tests: a confidentiality restriction's lift-by date is not before the day it was imposed.
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

use OCA\Decidiq\Listener\ConfidentialityTermGuardListener;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Listener\ConfidentialityTermGuardListener
 * @uses   \OCA\Decidiq\Listener\SaveGuardEvent
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
 */
class ConfidentialityTermGuardListenerTest extends TestCase {

	/**
	 * The board's restriction, imposed on 10 September 2026.
	 *
	 * @var array<string, mixed>
	 */
	private const RESTRICTION = [
		'_schemaSlug' => 'confidentiality-restriction',
		'scope' => 'document',
		'ground' => 'ground-woo-5-1-2-b',
		'imposedBy' => 'executive-board',
		'imposedAt' => '2026-09-10T10:00:00+02:00',
		'lifecycle' => 'imposed',
	];

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * A lift-by date before the imposed day is refused on create, naming both dates.
	 *
	 * @return void
	 */
	public function testALiftByBeforeTheImposedDayIsRefusedNamingBothDates(): void {
		$event = new ObjectCreatingEvent($this->entity(self::RESTRICTION + ['liftBy' => '2026-09-01']));

		(new ConfidentialityTermGuardListener())->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			'The confidentiality was not saved: the lift-by date 2026-09-01 is before the date it was imposed, 2026-09-10.',
			$event->getErrors()['message']
		);
	}//end testALiftByBeforeTheImposedDayIsRefusedNamingBothDates()

	/**
	 * The same holds on an update that moves the date back.
	 *
	 * @return void
	 */
	public function testMovingTheLiftByBeforeTheImposedDayOnUpdateIsRefused(): void {
		$old   = $this->entity(self::RESTRICTION + ['liftBy' => '2027-07-01']);
		$event = new ObjectUpdatingEvent($this->entity(self::RESTRICTION + ['liftBy' => '2026-09-09']), $old);

		(new ConfidentialityTermGuardListener())->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('2026-09-09', (string)$event->getErrors()['message']);
	}//end testMovingTheLiftByBeforeTheImposedDayOnUpdateIsRefused()

	/**
	 * A later date, the imposed day itself and no date at all pass.
	 *
	 * @return void
	 */
	public function testALaterDateTheSameDayAndNoDatePass(): void {
		$guard = new ConfidentialityTermGuardListener();

		foreach (['2027-07-01', '2026-09-10', null, ''] as $liftBy) {
			$event = new ObjectCreatingEvent($this->entity(self::RESTRICTION + ['liftBy' => $liftBy]));
			$guard->handle($event);
			self::assertFalse($event->isPropagationStopped(), 'liftBy ' . var_export($liftBy, true) . ' passes');
		}
	}//end testALaterDateTheSameDayAndNoDatePass()

	/**
	 * The imposed day is read in the offset it was written in, not in UTC.
	 *
	 * @return void
	 */
	public function testTheImposedDayIsReadInItsOwnOffset(): void {
		$guard = new ConfidentialityTermGuardListener();

		// 00:30 in Amsterdam is still 9 September in UTC; the day is the 10th.
		self::assertNull($guard->refusal(['imposedAt' => '2026-09-10T00:30:00+02:00', 'liftBy' => '2026-09-10']));
		self::assertNotNull($guard->refusal(['imposedAt' => '2026-09-10T00:30:00+02:00', 'liftBy' => '2026-09-09']));
	}//end testTheImposedDayIsReadInItsOwnOffset()

	/**
	 * An unreadable date is left to the schema's format check.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateIsNotJudged(): void {
		self::assertNull((new ConfidentialityTermGuardListener())->refusal(['imposedAt' => 'not a date', 'liftBy' => '2026-09-01']));
	}//end testAnUnreadableDateIsNotJudged()

	/**
	 * Another schema's record is never judged.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision', 'imposedAt' => '2026-09-10', 'liftBy' => '2026-09-01', 'ground' => 'x']));

		(new ConfidentialityTermGuardListener())->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * The guard is subscribed before create and before update, for the restriction schema only.
	 *
	 * @return void
	 */
	public function testTheGuardIsSubscribedOnCreateAndUpdate(): void {
		$mine = array_values(
			array_filter(
				SaveGuardSubscriptions::ALL,
				static fn (array $guard): bool => $guard['listener'] === ConfidentialityTermGuardListener::class
			)
		);

		self::assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class], array_column($mine, 'event'));
		self::assertSame([['confidentiality-restriction'], ['confidentiality-restriction']], array_column($mine, 'schemas'));
	}//end testTheGuardIsSubscribedOnCreateAndUpdate()
}//end class
