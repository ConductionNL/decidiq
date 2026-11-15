<?php

/**
 * Unit tests for GovernanceBodyRequestedEvent.
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

use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the governance-body request: optional collections default empty and the result slots start unanswered (body id reads as empty string).
 *
 * @covers \OCA\Decidiq\Event\GovernanceBodyRequestedEvent
 */
class GovernanceBodyRequestedEventTest extends TestCase {
	/**
	 * Optional fields default to empty values.
	 *
	 * @return void
	 */
	public function testOptionalFieldsDefaultEmpty(): void {
		$event = new GovernanceBodyRequestedEvent(sourceApp: 'a', externalReference: 'r', name: 'Board', bodyType: 'board', domain: 'governance', active: false);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertFalse($event->isActive());
		$this->assertSame([], $event->getAttributes());
		$this->assertSame([], $event->getMembers());
		$this->assertSame('', $event->getActorId());
		$this->assertSame('', $event->getCorrelationId());
	}//end testOptionalFieldsDefaultEmpty()

	/**
	 * Unanswered, the body id is an empty string and nothing is created or handled.
	 *
	 * @return void
	 */
	public function testResultSlotsStartUnanswered(): void {
		$event = new GovernanceBodyRequestedEvent(sourceApp: 'a', externalReference: 'r', name: 'n', bodyType: 't', domain: 'd', active: true);

		$this->assertSame('', $event->getGovernanceBodyId());
		$this->assertFalse($event->isCreated());
		$this->assertFalse($event->isHandled());
	}//end testResultSlotsStartUnanswered()

	/**
	 * The answer is recorded and the request stays as sent.
	 *
	 * @return void
	 */
	public function testResultSlotsRecordTheAnswer(): void {
		$event = new GovernanceBodyRequestedEvent(
			sourceApp: 'pipelinq',
			externalReference: 'BOARD-1',
			name: 'Supervisory board',
			bodyType: 'board',
			domain: 'governance',
			active: true,
			attributes: ['quorum' => 3],
			members: [['userId' => 'alice', 'role' => 'chair']],
			actorId: 'admin',
			correlationId: 'c-2',
		);

		$event->setGovernanceBodyId('gb-7');
		$event->setCreated(true);
		$event->setHandled(true);

		$this->assertSame('gb-7', $event->getGovernanceBodyId());
		$this->assertTrue($event->isCreated());
		$this->assertTrue($event->isHandled());
		$this->assertSame(
			['pipelinq', 'BOARD-1', 'Supervisory board', 'board', 'governance', true, ['quorum' => 3], [['userId' => 'alice', 'role' => 'chair']], 'admin', 'c-2'],
			[
				$event->getSourceApp(), $event->getExternalReference(), $event->getName(), $event->getBodyType(),
				$event->getDomain(), $event->isActive(), $event->getAttributes(), $event->getMembers(),
				$event->getActorId(), $event->getCorrelationId(),
			]
		);
	}//end testResultSlotsRecordTheAnswer()
}//end class
