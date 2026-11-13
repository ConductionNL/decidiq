<?php

/**
 * Unit tests for DecisionRequestedEvent.
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

use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the decision request: the decision type defaults to contract, other optional fields to empty, and the result slots start unanswered (decision id null).
 *
 * @covers \OCA\Decidiq\Event\DecisionRequestedEvent
 */
class DecisionRequestedEventTest extends TestCase {
	/**
	 * A minimal request is a contract decision with empty optional fields.
	 *
	 * @return void
	 */
	public function testOptionalFieldsDefaultToAContractDecision(): void {
		$event = new DecisionRequestedEvent(sourceApp: 'a', subjectRegister: 'r', subjectSchema: 's', subjectId: 'i');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('contract', $event->getDecisionType());
		$this->assertSame('', $event->getSubjectLabel());
		$this->assertSame('', $event->getActorId());
		$this->assertSame([], $event->getPayload());
		$this->assertSame('', $event->getExternalReference());
		$this->assertSame('', $event->getCorrelationId());
	}//end testOptionalFieldsDefaultToAContractDecision()

	/**
	 * Unanswered, there is no decision id and the request is not handled.
	 *
	 * @return void
	 */
	public function testResultSlotsStartUnanswered(): void {
		$event = new DecisionRequestedEvent(sourceApp: 'a', subjectRegister: 'r', subjectSchema: 's', subjectId: 'i');

		$this->assertNull($event->getDecisionId());
		$this->assertFalse($event->isHandled());
	}//end testResultSlotsStartUnanswered()

	/**
	 * The answer is recorded and the request fields stay as sent.
	 *
	 * @return void
	 */
	public function testResultSlotsRecordTheAnswer(): void {
		$event = new DecisionRequestedEvent(
			sourceApp: 'pipelinq',
			subjectRegister: 'crm',
			subjectSchema: 'lead',
			subjectId: 'l-1',
			subjectLabel: 'Lead 1',
			decisionType: 'budget',
			actorId: 'alice',
			payload: ['amount' => 100],
			externalReference: 'EXT',
			correlationId: 'c-1',
		);

		$event->setDecisionId('d-9');
		$event->setHandled(true);

		$this->assertSame('d-9', $event->getDecisionId());
		$this->assertTrue($event->isHandled());
		$this->assertSame(
			['pipelinq', 'crm', 'lead', 'l-1', 'Lead 1', 'budget', 'alice', ['amount' => 100], 'EXT', 'c-1'],
			[
				$event->getSourceApp(), $event->getSubjectRegister(), $event->getSubjectSchema(), $event->getSubjectId(),
				$event->getSubjectLabel(), $event->getDecisionType(), $event->getActorId(), $event->getPayload(),
				$event->getExternalReference(), $event->getCorrelationId(),
			]
		);
	}//end testResultSlotsRecordTheAnswer()
}//end class
