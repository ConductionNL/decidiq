<?php

/**
 * Unit tests for DecisionStateRequestedEvent.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Event;

use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the decision-state query: unanswered slots, and getStatus() derived from the envelope (null without one, pending when the envelope lacks a status).
 *
 * @covers \OCA\Decidiq\Event\DecisionStateRequestedEvent
 */
class DecisionStateRequestedEventTest extends TestCase {
	/**
	 * Unanswered, nothing is handled, permitted or found, and there is no status.
	 *
	 * @return void
	 */
	public function testResultSlotsStartUnanswered(): void {
		$event = new DecisionStateRequestedEvent(sourceApp: 'a', decisionId: 'd-1');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('', $event->getActorId());
		$this->assertFalse($event->isHandled());
		$this->assertFalse($event->isPermitted());
		$this->assertFalse($event->isFound());
		$this->assertNull($event->getEnvelope());
		$this->assertNull($event->getStatus());
	}//end testResultSlotsStartUnanswered()

	/**
	 * The status is read from the envelope.
	 *
	 * @return void
	 */
	public function testStatusIsReadFromTheEnvelope(): void {
		$event = new DecisionStateRequestedEvent(sourceApp: 'a', decisionId: 'd-1', actorId: 'alice');
		$event->setEnvelope(['decisionId' => 'd-1', 'status' => 'approved']);

		$this->assertSame('approved', $event->getStatus());
		$this->assertSame(['decisionId' => 'd-1', 'status' => 'approved'], $event->getEnvelope());
		$this->assertSame(['a', 'd-1', 'alice'], [$event->getSourceApp(), $event->getDecisionId(), $event->getActorId()]);
	}//end testStatusIsReadFromTheEnvelope()

	/**
	 * An envelope without a status reads as pending; a non-string status is cast.
	 *
	 * @return void
	 */
	public function testEnvelopeWithoutStatusReadsAsPending(): void {
		$event = new DecisionStateRequestedEvent(sourceApp: 'a', decisionId: 'd-1');
		$event->setEnvelope(['decisionId' => 'd-1']);
		$this->assertSame('pending', $event->getStatus());

		$event->setEnvelope(['status' => 3]);
		$this->assertSame('3', $event->getStatus());
	}//end testEnvelopeWithoutStatusReadsAsPending()

	/**
	 * Handled, permitted and found are independent answers.
	 *
	 * @return void
	 */
	public function testAnswerFlagsAreIndependent(): void {
		$event = new DecisionStateRequestedEvent(sourceApp: 'a', decisionId: 'd-1');
		$event->setHandled(true);
		$event->setFound(true);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->isFound());
		$this->assertFalse($event->isPermitted());

		$event->setPermitted(true);
		$event->setFound(false);
		$this->assertTrue($event->isPermitted());
		$this->assertFalse($event->isFound());
	}//end testAnswerFlagsAreIndependent()
}//end class
