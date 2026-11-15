<?php

/**
 * Unit tests for ApprovalRouteConcludedEvent.
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

use OCA\Decidiq\Event\ApprovalRouteConcludedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the route-concluded announcement: only subject, source app and outcome are required; the rest default empty.
 *
 * @covers \OCA\Decidiq\Event\ApprovalRouteConcludedEvent
 */
class ApprovalRouteConcludedEventTest extends TestCase {
	/**
	 * A minimal announcement carries empty actor, references and collections.
	 *
	 * @return void
	 */
	public function testOptionalFieldsDefaultEmpty(): void {
		$event = new ApprovalRouteConcludedEvent(subject: 's-1', sourceApp: 'pipelinq', outcome: 'approved');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame(['s-1', 'pipelinq', 'approved'], [$event->getSubject(), $event->getSourceApp(), $event->getOutcome()]);
		$this->assertSame('', $event->getActor());
		$this->assertSame('', $event->getCorrelationId());
		$this->assertSame('', $event->getSubjectSchema());
		$this->assertSame('', $event->getExternalReference());
		$this->assertSame([], $event->getActions());
		$this->assertSame([], $event->getClearance());
	}//end testOptionalFieldsDefaultEmpty()

	/**
	 * The action trail and clearance are handed on unchanged, order and keys kept.
	 *
	 * @return void
	 */
	public function testActionTrailAndClearanceAreHandedOnUnchanged(): void {
		$actions   = [['actor' => 'a', 'action' => 'approve'], ['actor' => 'b', 'action' => 'return', 'step' => 1]];
		$clearance = ['level' => 'confidential', 'groups' => ['board']];
		$event     = new ApprovalRouteConcludedEvent(
			subject: 's-2',
			sourceApp: 'shillinq',
			outcome: 'rejected',
			actor: 'b',
			correlationId: 'c-1',
			subjectSchema: 'invoice',
			externalReference: 'INV-9',
			actions: $actions,
			clearance: $clearance,
		);

		$this->assertSame($actions, $event->getActions());
		$this->assertSame($clearance, $event->getClearance());
		$this->assertSame(['b', 'c-1', 'invoice', 'INV-9'], [$event->getActor(), $event->getCorrelationId(), $event->getSubjectSchema(), $event->getExternalReference()]);
	}//end testActionTrailAndClearanceAreHandedOnUnchanged()
}//end class
