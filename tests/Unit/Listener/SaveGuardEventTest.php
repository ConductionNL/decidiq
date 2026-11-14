<?php

/**
 * Tests: what a save guard reads from, and writes onto, a save event.
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

use OCA\Decidiq\Listener\SaveGuardEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Listener\SaveGuardEvent
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
 */
class SaveGuardEventTest extends TestCase {

	/**
	 * A creating event gives its object, an updating event its new object, any other event nothing.
	 *
	 * @return void
	 */
	public function testTheEntityIsTheOneAboutToBeSaved(): void {
		$created = $this->createMock(ObjectEntity::class);
		$old     = $this->createMock(ObjectEntity::class);
		$new     = $this->createMock(ObjectEntity::class);

		self::assertSame($created, SaveGuardEvent::entityOf(event: new ObjectCreatingEvent($created)));
		self::assertSame($new, SaveGuardEvent::entityOf(event: new ObjectUpdatingEvent($new, $old)));
		self::assertNull(SaveGuardEvent::entityOf(event: new Event()));
	}//end testTheEntityIsTheOneAboutToBeSaved()

	/**
	 * The first non-empty schema key decides, case-insensitively; no key gives null.
	 *
	 * @return void
	 */
	public function testTheFirstSchemaKeyDecides(): void {
		self::assertTrue(SaveGuardEvent::namesSchema(row: ['_schemaSlug' => 'Meeting'], schema: 'meeting'));
		self::assertFalse(SaveGuardEvent::namesSchema(row: ['_schemaSlug' => 'motion', 'schema' => 'meeting'], schema: 'meeting'));
		self::assertTrue(SaveGuardEvent::namesSchema(row: ['_schemaSlug' => '', '_schema' => 7, 'schema' => 'meeting'], schema: 'meeting'));
		self::assertNull(SaveGuardEvent::namesSchema(row: ['title' => 'x', 'schema' => ''], schema: 'meeting'));
	}//end testTheFirstSchemaKeyDecides()

	/**
	 * A refusal sets the message and stops propagation; another event is left alone.
	 *
	 * @return void
	 */
	public function testARefusalSetsTheMessageAndStopsPropagation(): void {
		$event = new ObjectCreatingEvent($this->createMock(ObjectEntity::class));
		SaveGuardEvent::refuse(event: $event, message: 'Not saved.');

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'Not saved.'], $event->getErrors());

		$other = new Event();
		SaveGuardEvent::refuse(event: $other, message: 'Not saved.');
		self::assertFalse($other->isPropagationStopped());
	}//end testARefusalSetsTheMessageAndStopsPropagation()
}//end class
