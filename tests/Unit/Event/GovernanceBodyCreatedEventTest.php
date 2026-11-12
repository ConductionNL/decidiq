<?php

/**
 * Unit tests for GovernanceBodyCreatedEvent.
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

use OCA\Decidiq\Event\GovernanceBodyCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the governance-body announcement: the created flag distinguishes a new body from an existing one matched by reference.
 *
 * @covers \OCA\Decidiq\Event\GovernanceBodyCreatedEvent
 */
class GovernanceBodyCreatedEventTest extends TestCase {
	/**
	 * A newly created body is announced with created=true and no correlation by default.
	 *
	 * @return void
	 */
	public function testNewBodyIsAnnouncedAsCreated(): void {
		$event = new GovernanceBodyCreatedEvent(governanceBodyId: 'gb-1', sourceApp: 'pipelinq', externalReference: 'BOARD', created: true);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertTrue($event->isCreated());
		$this->assertSame(['gb-1', 'pipelinq', 'BOARD', ''], [$event->getGovernanceBodyId(), $event->getSourceApp(), $event->getExternalReference(), $event->getCorrelationId()]);
	}//end testNewBodyIsAnnouncedAsCreated()

	/**
	 * A matched existing body is announced with created=false and carries the correlation id.
	 *
	 * @return void
	 */
	public function testExistingBodyIsAnnouncedAsNotCreated(): void {
		$event = new GovernanceBodyCreatedEvent(governanceBodyId: 'gb-2', sourceApp: 'a', externalReference: 'R', created: false, correlationId: 'c-3');

		$this->assertFalse($event->isCreated());
		$this->assertSame('c-3', $event->getCorrelationId());
	}//end testExistingBodyIsAnnouncedAsNotCreated()
}//end class
