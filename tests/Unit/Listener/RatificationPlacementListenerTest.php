<?php

/**
 * A new regular meeting of the ratifying body takes the pending ratifications.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\RatificationPlacementListener;
use OCA\Decidiq\Service\UrgentRatificationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Regular meetings are handed to the placement, others are not, failures are logged.
 *
 * @covers \OCA\Decidiq\Listener\RatificationPlacementListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 */
class RatificationPlacementListenerTest extends TestCase {

	/**
	 * A created-event over a meeting payload, uuid raad-1.
	 *
	 * @param array<string, mixed> $row The meeting.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function event(array $row): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('getUuid')->willReturn('raad-1');
		return new ObjectCreatedEvent($entity);
	}//end event()

	/**
	 * A regular meeting is placed, with its uuid filled in.
	 *
	 * @return void
	 */
	public function testARegularMeetingIsPlaced(): void {
		$objects   = new \stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$ratification = $this->createMock(UrgentRatificationService::class);
		$ratification->expects($this->once())->method('placePending')
			->with($objects, ['meetingType' => 'regular', 'governanceBody' => 'body-raad', 'id' => 'raad-1'])
			->willReturn(1);

		(new RatificationPlacementListener(container: $container, ratification: $ratification, logger: $this->createMock(LoggerInterface::class)))
			->handle($this->event(['meetingType' => 'regular', 'governanceBody' => 'body-raad']));
	}//end testARegularMeetingIsPlaced()

	/**
	 * Another meeting type is not looked at.
	 *
	 * @return void
	 */
	public function testAnotherMeetingTypeIsIgnored(): void {
		$ratification = $this->createMock(UrgentRatificationService::class);
		$ratification->expects($this->never())->method('placePending');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		(new RatificationPlacementListener(container: $container, ratification: $ratification, logger: $this->createMock(LoggerInterface::class)))
			->handle($this->event(['meetingType' => 'extraordinary']));
	}//end testAnotherMeetingTypeIsIgnored()

	/**
	 * A failed placement is logged, not thrown: the meeting is already saved.
	 *
	 * @return void
	 */
	public function testAFailedPlacementIsLogged(): void {
		$ratification = $this->createMock(UrgentRatificationService::class);
		$ratification->method('placePending')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: pending ratifications were not placed on a new meeting',
			['meeting' => 'raad-1', 'error' => 'down']
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		(new RatificationPlacementListener(container: $container, ratification: $ratification, logger: $logger))
			->handle($this->event(['meetingType' => 'regular']));
	}//end testAFailedPlacementIsLogged()
}//end class
