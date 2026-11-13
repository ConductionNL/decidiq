<?php

/**
 * Edge cases of the meeting defaults listener.
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

use OCA\Decidiq\Listener\MeetingDefaultsListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only a meeting being created takes defaults; a type given as an object is
 * read by id or uuid; the public flag comes from the type only when the
 * meeting leaves it open; an unusable quorum is not copied; and a lookup
 * that fails or an event without an object is skipped, logged where it threw.
 *
 * @covers \OCA\Decidiq\Listener\MeetingDefaultsListener
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
 */
class MeetingDefaultsListenerEdgeTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The listener's logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var MeetingDefaultsListener
	 */
	private MeetingDefaultsListener $listener;

	/**
	 * Set up the listener over the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new MeetingDefaultsListener(logger: $this->logger, objectService: $this->objectService);
	}//end setUp()

	/**
	 * An entity whose payload is the given row.
	 *
	 * @param array<string, mixed> $row The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * Answer find() for the given id with the given row.
	 *
	 * @param string $id The object id
	 * @param array<string, mixed> $row The row
	 *
	 * @return void
	 */
	private function storeHolds(string $id, array $row): void {
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $asked): ?ObjectEntity => (string)$asked === $id ? $this->entity($row) : null
		);
	}//end storeHolds()

	/**
	 * Updates and other schemas take no defaults.
	 *
	 * @return void
	 */
	public function testOnlyACreatedMeetingTakesDefaults(): void {
		$this->objectService->expects(self::never())->method('find');
		$meeting = $this->entity(['type' => 'type-1']);

		$updating = new ObjectUpdatingEvent($meeting, $meeting);
		$this->listener->handle($updating);
		$agendaItem = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'Agenda-Item', 'type' => 'type-1']));
		$this->listener->handle($agendaItem);

		self::assertSame([], $updating->getModifiedData());
		self::assertSame([], $agendaItem->getModifiedData());
	}//end testOnlyACreatedMeetingTakesDefaults()

	/**
	 * A type given as an object is read by uuid; its public flag fills an open
	 * one; a zero quorum is not copied.
	 *
	 * @return void
	 */
	public function testATypeObjectFillsTheOpenPublicFlagButNoZeroQuorum(): void {
		$this->storeHolds(id: 'type-1', row: ['isPublic' => true, 'defaultQuorum' => 0]);
		$event = new ObjectCreatingEvent($this->entity(['type' => ['uuid' => 'type-1']]));

		$this->listener->handle($event);

		self::assertSame(['isPublic' => true], $event->getModifiedData());
	}//end testATypeObjectFillsTheOpenPublicFlagButNoZeroQuorum()

	/**
	 * A public flag the meeting already sets is kept.
	 *
	 * @return void
	 */
	public function testAPublicFlagTheMeetingSetsIsKept(): void {
		$this->storeHolds(id: 'type-1', row: ['isPublic' => true]);
		$event = new ObjectCreatingEvent($this->entity(['type' => ['id' => 'type-1'], 'isPublic' => false]));

		$this->listener->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAPublicFlagTheMeetingSetsIsKept()

	/**
	 * A type lookup that throws is treated as no type.
	 *
	 * @return void
	 */
	public function testAFailingTypeLookupTakesNoDefaults(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects(self::never())->method('warning');
		$event = new ObjectCreatingEvent($this->entity(['type' => 'type-1']));

		$this->listener->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAFailingTypeLookupTakesNoDefaults()

	/**
	 * An event without an object is skipped and logged.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnObjectIsLogged(): void {
		$this->logger->expects(self::once())->method('warning')->with('Decidiq: meeting defaults skipped', self::callback(
			fn (array $context): bool => is_string($context['exception'] ?? null) && $context['exception'] !== ''
		));
		$event = new ObjectCreatingEvent(null);

		$this->listener->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnEventWithoutAnObjectIsLogged()
}//end class
