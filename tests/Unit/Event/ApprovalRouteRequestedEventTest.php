<?php

/**
 * Unit tests for ApprovalRouteRequestedEvent.
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

use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the route request: optional fields default empty and not-default, and the result slots start unanswered (route id reads as empty string, never null).
 *
 * @covers \OCA\Decidiq\Event\ApprovalRouteRequestedEvent
 */
class ApprovalRouteRequestedEventTest extends TestCase {
	/**
	 * Optional request fields default to empty values and a non-default route.
	 *
	 * @return void
	 */
	public function testOptionalFieldsDefault(): void {
		$event = new ApprovalRouteRequestedEvent(sourceApp: 'a', externalReference: 'ref', name: 'Route', steps: [['role' => 'x']]);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame([['role' => 'x']], $event->getSteps());
		$this->assertSame('', $event->getSubjectType());
		$this->assertSame('', $event->getDescription());
		$this->assertFalse($event->isDefault());
		$this->assertSame('', $event->getSubject());
		$this->assertSame('', $event->getSubjectSchema());
		$this->assertSame('', $event->getActorId());
		$this->assertSame('', $event->getCorrelationId());
		$this->assertSame([], $event->getActors());
		$this->assertSame('', $event->getDeadline());
	}//end testOptionalFieldsDefault()

	/**
	 * Unanswered, the route id is an empty string and nothing is created or counted.
	 *
	 * @return void
	 */
	public function testResultSlotsStartUnanswered(): void {
		$event = new ApprovalRouteRequestedEvent(sourceApp: 'a', externalReference: 'r', name: 'n', steps: []);

		$this->assertSame('', $event->getRouteId());
		$this->assertFalse($event->isCreated());
		$this->assertSame(0, $event->getStageCount());
		$this->assertFalse($event->isHandled());
	}//end testResultSlotsStartUnanswered()

	/**
	 * The engine's answer is recorded slot by slot; the request stays as sent.
	 *
	 * @return void
	 */
	public function testResultSlotsRecordTheAnswer(): void {
		$event = new ApprovalRouteRequestedEvent(
			sourceApp: 'pipelinq',
			externalReference: 'LEAD-1',
			name: 'Board approval',
			steps: [['role' => 'chair'], ['role' => 'secretary']],
			subjectType: 'lead',
			description: 'Two-step',
			isDefault: true,
			subject: 's-1',
			subjectSchema: 'lead',
			actorId: 'alice',
			correlationId: 'c-1',
			actors: ['alice', 'bob'],
			deadline: '2026-12-01',
		);

		$event->setRouteId('route-42');
		$event->setCreated(true);
		$event->setStageCount(2);
		$event->setHandled(true);

		$this->assertSame('route-42', $event->getRouteId());
		$this->assertTrue($event->isCreated());
		$this->assertSame(2, $event->getStageCount());
		$this->assertTrue($event->isHandled());
		$this->assertSame(
			['pipelinq', 'LEAD-1', 'Board approval', 'lead', 'Two-step', true, 's-1', 'lead', 'alice', 'c-1', ['alice', 'bob'], '2026-12-01'],
			[
				$event->getSourceApp(), $event->getExternalReference(), $event->getName(), $event->getSubjectType(),
				$event->getDescription(), $event->isDefault(), $event->getSubject(), $event->getSubjectSchema(),
				$event->getActorId(), $event->getCorrelationId(), $event->getActors(), $event->getDeadline(),
			]
		);
	}//end testResultSlotsRecordTheAnswer()
}//end class
