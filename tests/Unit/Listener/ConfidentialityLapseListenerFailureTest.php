<?php

/**
 * Tests: what the confidentiality lapse listener ignores and how it fails.
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
use OCA\Decidiq\Listener\ConfidentialityLapseListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The paths the happy-path test leaves out: events that are not a save of an
 * existing object, an unreadable ground, a failed save, and rows that do not
 * actually belong to the meeting or item they were read for.
 *
 * @covers \OCA\Decidiq\Listener\ConfidentialityLapseListener
 * @uses   \OCA\Decidiq\Service\SystemObjectRows
 * @uses   \OCA\Decidiq\Listener\LapseMoment
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
 */
class ConfidentialityLapseListenerFailureTest extends TestCase {

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row  The record.
	 * @param string|null          $uuid The uuid the entity reports.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row, ?string $uuid=null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($uuid ?? ($row['id'] ?? null));
		return $entity;
	}//end entity()

	/**
	 * A restriction on item-9 that lapses when nobody ratifies it.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function restriction(array $changes=[]): array {
		return $changes + [
			'id' => 'r-tarieven',
			'ground' => 'ground-ratify',
			'lifecycle' => 'imposed',
			'ratificationAgendaItem' => 'item-9',
		];
	}//end restriction()

	/**
	 * An object service that returns the given rows per schema and the ratify ground.
	 *
	 * @param list<array<string, mixed>> $items        Agenda items.
	 * @param list<array<string, mixed>> $restrictions Restrictions.
	 *
	 * @return ObjectServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function objects(array $items, array $restrictions): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($items, $restrictions): array {
				$schema = array_values($args)[0]['filters']['schema'];
				$source = $schema === 'agenda-item' ? $items : $restrictions;
				return array_map(fn (array $row): ObjectEntity => $this->entity($row), $source);
			}
		);
		$objects->method('find')->willReturn($this->entity(['id' => 'ground-ratify', 'requiresRatification' => true]));
		return $objects;
	}//end objects()

	/**
	 * Events that are not an update of an existing object are ignored without a read.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method('findAll');
		$objects->expects(self::never())->method('saveObject');
		$listener = new ConfidentialityLapseListener($objects, $this->createMock(LoggerInterface::class));

		$listener->handle(new Event());
		$listener->handle(new ObjectCreatedEvent($this->entity(['id' => 'meeting-1', 'lifecycle' => 'closed'])));
		$listener->handle(new ObjectUpdatedEvent(null, $this->entity(['id' => 'meeting-1', 'lifecycle' => 'opened'])));
	}//end testOtherEventsAreIgnored()

	/**
	 * A meeting without any id does not read its items.
	 *
	 * @return void
	 */
	public function testAMeetingWithoutAnIdLapsesNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method('findAll');
		$listener = new ConfidentialityLapseListener($objects, $this->createMock(LoggerInterface::class));

		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(['lifecycle' => 'closed'], ''),
				$this->entity(['lifecycle' => 'opened'], '')
			)
		);
		$listener->lapseAtClose('', new DateTimeImmutable());
	}//end testAMeetingWithoutAnIdLapsesNothing()

	/**
	 * Without an id in the data the entity's uuid names the closing meeting.
	 *
	 * @return void
	 */
	public function testTheEntityUuidNamesTheMeeting(): void {
		$item    = ['id' => 'item-9', 'meeting' => 'meeting-15-oct'];
		$objects = $this->objects([$item], [self::restriction()]);
		$objects->expects(self::once())->method('saveObject')->with(
			self::callback(static fn (array $o): bool => $o['lifecycle'] === 'lapsed' && $o['lapsedAtMeeting'] === 'meeting-15-oct'),
			self::anything(),
			'decidiq',
			'confidentiality-restriction',
			'r-tarieven'
		);
		$listener = new ConfidentialityLapseListener($objects, $this->createMock(LoggerInterface::class));

		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(['lifecycle' => 'closed'], 'meeting-15-oct'),
				$this->entity(['lifecycle' => 'opened'], 'meeting-15-oct')
			)
		);
	}//end testTheEntityUuidNamesTheMeeting()

	/**
	 * Rows the filter returned for another meeting or item, or an item without an id, are skipped.
	 *
	 * @return void
	 */
	public function testRowsOfAnotherMeetingOrItemAreSkipped(): void {
		$items = [
			['id' => 'item-other', 'meeting' => 'meeting-other'],
			['meeting' => 'meeting-15-oct'],
			['id' => 'item-9', 'meeting' => 'meeting-15-oct'],
		];
		$objects = $this->objects($items, [self::restriction(['ratificationAgendaItem' => 'item-elsewhere'])]);
		$objects->expects(self::never())->method('saveObject');
		$listener = new ConfidentialityLapseListener($objects, $this->createMock(LoggerInterface::class));

		$listener->lapseAtClose('meeting-15-oct', new DateTimeImmutable());
	}//end testRowsOfAnotherMeetingOrItemAreSkipped()

	/**
	 * A clerk's "not ratified" on an item without a meeting lapses it without naming one.
	 *
	 * @return void
	 */
	public function testANotRatifiedItemWithoutAMeetingNamesNone(): void {
		$item    = ['id' => 'item-9', 'ratificationOutcome' => 'not-ratified'];
		$objects = $this->objects([], [self::restriction(['@self' => ['id' => 'r-self']])]);
		unset($item['meeting']);
		$saved = null;
		$objects->expects(self::once())->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use (&$saved): ObjectEntity {
				$saved = array_values($args);
				return $this->entity([]);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(
			'Decidiq: a confidentiality lapsed because the body did not ratify it',
			['restriction' => 'r-tarieven', 'meeting' => '', 'agendaItem' => 'item-9']
		);
		$listener = new ConfidentialityLapseListener($objects, $logger);

		$listener->handle(new ObjectUpdatedEvent($this->entity($item), $this->entity(['id' => 'item-9'])));

		self::assertSame('lapsed', $saved[0]['lifecycle']);
		self::assertArrayNotHasKey('lapsedAtMeeting', $saved[0]);
		self::assertArrayNotHasKey('@self', $saved[0]);
	}//end testANotRatifiedItemWithoutAMeetingNamesNone()

	/**
	 * A restriction known only by its @self id is saved under that id.
	 *
	 * @return void
	 */
	public function testTheSelfIdIsUsedWhenTheRowHasNone(): void {
		$item        = ['id' => 'item-9', 'meeting' => 'meeting-15-oct', 'ratificationOutcome' => 'not-ratified'];
		$restriction = self::restriction(['@self' => ['id' => 'r-self']]);
		unset($restriction['id']);
		$objects = $this->objects([], [$restriction]);
		$objects->expects(self::once())->method('saveObject')->with(
			self::anything(),
			self::anything(),
			'decidiq',
			'confidentiality-restriction',
			'r-self'
		);
		$listener = new ConfidentialityLapseListener($objects, $this->createMock(LoggerInterface::class));

		$listener->handle(new ObjectUpdatedEvent($this->entity($item), $this->entity(['id' => 'item-9'])));
	}//end testTheSelfIdIsUsedWhenTheRowHasNone()

	/**
	 * A failed save is logged as an error naming the restriction, meeting and item.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLogged(): void {
		$item    = ['id' => 'item-9', 'meeting' => 'meeting-15-oct'];
		$objects = $this->objects([$item], [self::restriction()]);
		$objects->method('saveObject')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('info');
		$logger->expects(self::once())->method('error')->with(
			'Decidiq: a confidentiality that should lapse could not be saved',
			['restriction' => 'r-tarieven', 'meeting' => 'meeting-15-oct', 'agendaItem' => 'item-9', 'error' => 'database gone']
		);

		(new ConfidentialityLapseListener($objects, $logger))->lapseAtClose('meeting-15-oct', new DateTimeImmutable());
	}//end testAFailedSaveIsLogged()

	/**
	 * A ground that cannot be read is warned about and nothing lapses.
	 *
	 * @return void
	 */
	public function testAnUnreadableGroundLapsesNothing(): void {
		$item    = ['id' => 'item-9', 'meeting' => 'meeting-15-oct'];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($item): array {
				$schema = array_values($args)[0]['filters']['schema'];
				return $schema === 'agenda-item' ? [$this->entity($item)] : [$this->entity(self::restriction()), $this->entity(self::restriction(['ground' => '']))];
			}
		);
		$objects->expects(self::once())->method('find')->willThrowException(new RuntimeException('no access'));
		$objects->expects(self::never())->method('saveObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: a confidentiality ground could not be read; nothing lapses',
			['ground' => 'ground-ratify', 'error' => 'no access']
		);

		(new ConfidentialityLapseListener($objects, $logger))->lapseAtClose('meeting-15-oct', new DateTimeImmutable());
	}//end testAnUnreadableGroundLapsesNothing()
}//end class
