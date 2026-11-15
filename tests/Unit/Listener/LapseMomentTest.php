<?php

/**
 * Unit tests for LapseMoment: the meeting, item and moment of a lapse.
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

use DateTimeImmutable;
use OCA\Decidiq\Listener\LapseMoment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LapseMoment.
 */
final class LapseMomentTest extends TestCase {

	/**
	 * forItem() keeps the meeting, the moment and whether the meeting closed, and names the item.
	 *
	 * @return void
	 */
	public function testForItemKeepsTheMomentAndNamesTheItem(): void {
		$now    = new DateTimeImmutable('2026-11-14T10:00:00+00:00');
		$moment = new LapseMoment(meetingId: 'meet-1', now: $now, atClose: true);

		$forItem = $moment->forItem(itemId: 'item-7');

		$this->assertSame('', $moment->itemId);
		$this->assertNotSame($moment, $forItem);
		$this->assertSame(['meet-1', 'item-7', true], [$forItem->meetingId, $forItem->itemId, $forItem->atClose]);
		$this->assertSame($now, $forItem->now);
	}//end testForItemKeepsTheMomentAndNamesTheItem()
}//end class
