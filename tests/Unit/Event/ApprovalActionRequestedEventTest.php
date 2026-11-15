<?php

/**
 * Unit tests for ApprovalActionRequestedEvent.
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

use OCA\Decidiq\Event\ApprovalActionRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the approval-action request: optional fields default to a plain user action, and the result slots start unanswered and record what the engine answered without touching the request.
 *
 * @covers \OCA\Decidiq\Event\ApprovalActionRequestedEvent
 */
class ApprovalActionRequestedEventTest extends TestCase {
	/**
	 * Optional request fields default to a plain user action with no extras.
	 *
	 * @return void
	 */
	public function testOptionalFieldsDefaultToAPlainUserAction(): void {
		$event = new ApprovalActionRequestedEvent(sourceApp: 'pipelinq', subject: 's-1', actor: 'alice', action: 'approve');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame(0, $event->getReturnToStep());
		$this->assertSame('', $event->getComment());
		$this->assertSame('', $event->getAdvice());
		$this->assertSame('user', $event->getActorType());
		$this->assertSame('', $event->getOnBehalfOf());
		$this->assertSame('', $event->getMandate());
		$this->assertSame('', $event->getCorrelationId());
	}//end testOptionalFieldsDefaultToAPlainUserAction()

	/**
	 * Before any listener answers, nothing is recorded, completed, handled or refused.
	 *
	 * @return void
	 */
	public function testResultSlotsStartUnanswered(): void {
		$event = new ApprovalActionRequestedEvent(sourceApp: 'a', subject: 's', actor: 'u', action: 'reject');

		$this->assertFalse($event->isRecorded());
		$this->assertFalse($event->isCompleted());
		$this->assertFalse($event->isHandled());
		$this->assertSame('', $event->getRefusal());
		$this->assertFalse($event->isPropagationStopped());
	}//end testResultSlotsStartUnanswered()

	/**
	 * Each result slot records independently and leaves the request fields as sent.
	 *
	 * @return void
	 */
	public function testResultSlotsRecordIndependentlyOfTheRequest(): void {
		$event = new ApprovalActionRequestedEvent(
			sourceApp: 'pipelinq',
			subject: 's-9',
			actor: 'bob',
			action: 'return',
			returnToStep: 2,
			comment: 'needs work',
			advice: 'negative',
			actorType: 'agent',
			onBehalfOf: 'carol',
			mandate: 'm-1',
			correlationId: 'c-7',
		);

		$event->setHandled(true);
		$event->setRefusal('not the named actor');
		$this->assertTrue($event->isHandled());
		$this->assertFalse($event->isRecorded());
		$this->assertFalse($event->isCompleted());
		$this->assertSame('not the named actor', $event->getRefusal());

		$event->setRecorded(true);
		$event->setCompleted(true);
		$event->setRefusal('');
		$this->assertTrue($event->isRecorded());
		$this->assertTrue($event->isCompleted());
		$this->assertSame('', $event->getRefusal());

		$this->assertSame(
			['pipelinq', 's-9', 'bob', 'return', 2, 'needs work', 'negative', 'agent', 'carol', 'm-1', 'c-7'],
			[
				$event->getSourceApp(), $event->getSubject(), $event->getActor(), $event->getAction(),
				$event->getReturnToStep(), $event->getComment(), $event->getAdvice(), $event->getActorType(),
				$event->getOnBehalfOf(), $event->getMandate(), $event->getCorrelationId(),
			]
		);
	}//end testResultSlotsRecordIndependentlyOfTheRequest()
}//end class
