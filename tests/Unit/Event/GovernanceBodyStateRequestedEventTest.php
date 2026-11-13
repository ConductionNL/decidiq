<?php

/**
 * Unit tests for GovernanceBodyStateRequestedEvent.
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

use OCA\Decidiq\Event\GovernanceBodyStateRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the governance-body state query: lookup keys default empty, and found is derived from whether a body was set.
 *
 * @covers \OCA\Decidiq\Event\GovernanceBodyStateRequestedEvent
 */
class GovernanceBodyStateRequestedEventTest extends TestCase {
	/**
	 * Unanswered, nothing is handled or found, and lookup keys default empty.
	 *
	 * @return void
	 */
	public function testResultSlotsStartUnanswered(): void {
		$event = new GovernanceBodyStateRequestedEvent(sourceApp: 'pipelinq');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('', $event->getExternalReference());
		$this->assertSame('', $event->getGovernanceBodyId());
		$this->assertFalse($event->isHandled());
		$this->assertFalse($event->isFound());
		$this->assertNull($event->getGovernanceBody());
	}//end testResultSlotsStartUnanswered()

	/**
	 * Setting a body makes it found, even an empty one; handled stays a separate answer.
	 *
	 * @return void
	 */
	public function testSettingABodyMakesItFound(): void {
		$event = new GovernanceBodyStateRequestedEvent(sourceApp: 'a', externalReference: 'BOARD', governanceBodyId: 'gb-1');
		$event->setGovernanceBody([]);

		$this->assertTrue($event->isFound());
		$this->assertSame([], $event->getGovernanceBody());
		$this->assertFalse($event->isHandled());

		$event->setGovernanceBody(['id' => 'gb-1', 'name' => 'Board']);
		$event->setHandled(true);
		$this->assertSame(['id' => 'gb-1', 'name' => 'Board'], $event->getGovernanceBody());
		$this->assertTrue($event->isHandled());
		$this->assertSame(['a', 'BOARD', 'gb-1'], [$event->getSourceApp(), $event->getExternalReference(), $event->getGovernanceBodyId()]);
	}//end testSettingABodyMakesItFound()
}//end class
