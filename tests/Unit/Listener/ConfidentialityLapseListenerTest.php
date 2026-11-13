<?php

/**
 * Tests: a confidentiality the ratifying body did not ratify lapses.
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

use OCA\Decidiq\Listener\ConfidentialityLapseListener;
use OCA\Decidiq\Service\ConfidentialityRestrictions;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Listener\ConfidentialityLapseListener
 * @uses   \OCA\Decidiq\Service\ConfidentialityRestrictions
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
 */
class ConfidentialityLapseListenerTest extends TestCase {

	/**
	 * The restrictions saved during a test, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($row['id'] ?? null);
		return $entity;
	}//end entity()

	/**
	 * The college's restriction with its ratification item on the council meeting of 15 October.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function restriction(array $changes=[]): array {
		return $changes + [
			'id' => 'r-tarieven',
			'scope' => 'document',
			'targetDocument' => 'doc-tarieven',
			'ground' => 'ground-ratify',
			'imposedBy' => 'executive-board',
			'imposedByBody' => 'body-college',
			'imposedAt' => '2026-09-10T10:00:00+02:00',
			'lifecycle' => 'imposed',
			'ratificationAgendaItem' => 'item-9',
		];
	}//end restriction()

	/**
	 * The listener over the given items and restrictions.
	 *
	 * @param list<array<string, mixed>> $items        Agenda items.
	 * @param list<array<string, mixed>> $restrictions Restrictions.
	 *
	 * @return ConfidentialityLapseListener
	 */
	private function listener(array $items, array $restrictions): ConfidentialityLapseListener {
		$grounds = [
			'ground-ratify' => ['id' => 'ground-ratify', 'name' => 'Woo 5.1 lid 2 b', 'requiresRatification' => true],
			'ground-plain' => ['id' => 'ground-plain', 'name' => 'Statuten art. 12', 'requiresRatification' => false],
		];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($items, $restrictions): array {
				$filters = array_values($args)[0]['filters'];
				$source  = $filters['schema'] === 'agenda-item' ? $items : $restrictions;
				unset($filters['register'], $filters['schema']);
				$found = array_filter(
					$source,
					static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
				);
				return array_map(fn (array $row): ObjectEntity => $this->entity($row), array_values($found));
			}
		);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($grounds): ?ObjectEntity {
				$id = (string)array_values($args)[0];
				return isset($grounds[$id]) === true ? $this->entity($grounds[$id]) : null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				// The double receives every parameter of the stub's signature,
				// defaults filled in: object, extend, register, schema, uuid, ...
				[$object, , $register, $schema, $uuid] = array_values($args);
				self::assertSame(['decidiq', 'confidentiality-restriction'], [$register, $schema]);
				$this->saved[(string)$uuid] = $object;
				return $this->entity($object);
			}
		);

		return new ConfidentialityLapseListener($objects, new NullLogger());
	}//end listener()

	/**
	 * The council meeting moving from opened to closed.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function meetingCloses(): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			$this->entity(['id' => 'meeting-15-oct', 'title' => 'Raadsvergadering 15 oktober 2026', 'lifecycle' => 'closed']),
			$this->entity(['id' => 'meeting-15-oct', 'title' => 'Raadsvergadering 15 oktober 2026', 'lifecycle' => 'opened'])
		);
	}//end meetingCloses()

	/**
	 * The council does not ratify: the restriction lapses, naming the meeting.
	 *
	 * @return void
	 */
	public function testTheCouncilDoesNotRatify(): void {
		$item = ['id' => 'item-9', 'title' => 'Bekrachtiging geheimhouding', 'meeting' => 'meeting-15-oct'];

		$this->listener([$item], [self::restriction()])->handle($this->meetingCloses());

		self::assertSame(['r-tarieven'], array_keys($this->saved));
		$saved = $this->saved['r-tarieven'];
		self::assertSame('lapsed', $saved['lifecycle']);
		self::assertSame('meeting-15-oct', $saved['lapsedAtMeeting']);
		self::assertSame('item-9', $saved['ratificationAgendaItem']);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $saved['lapsedAt']));
		// Everything else the restriction said is kept: the save replaces the object.
		self::assertSame('doc-tarieven', $saved['targetDocument']);
		self::assertSame('2026-09-10T10:00:00+02:00', $saved['imposedAt']);
	}//end testTheCouncilDoesNotRatify()

	/**
	 * The item was postponed: the restriction stays imposed.
	 *
	 * @return void
	 */
	public function testThePostponedItemKeepsTheRestriction(): void {
		$item = ['id' => 'item-9', 'meeting' => 'meeting-15-oct', 'ratificationOutcome' => 'postponed'];

		$this->listener([$item], [self::restriction()])->handle($this->meetingCloses());

		self::assertSame([], $this->saved);
	}//end testThePostponedItemKeepsTheRestriction()

	/**
	 * The council ratifies: by the item or by the restriction's own decision, it never lapses.
	 *
	 * @return void
	 */
	public function testARatifiedRestrictionNeverLapses(): void {
		$ratifiedItem = ['id' => 'item-9', 'meeting' => 'meeting-15-oct', 'ratificationOutcome' => 'ratified'];
		$this->listener([$ratifiedItem], [self::restriction()])->handle($this->meetingCloses());

		$plainItem = ['id' => 'item-9', 'meeting' => 'meeting-15-oct'];
		$this->listener([$plainItem], [self::restriction(['ratificationDecision' => 'decision-bekrachtiging'])])->handle($this->meetingCloses());

		$this->listener([$plainItem], [self::restriction(['lifecycle' => 'ratified'])])->handle($this->meetingCloses());

		self::assertSame([], $this->saved);
	}//end testARatifiedRestrictionNeverLapses()

	/**
	 * A ground without ratification never lapses, nor does one that cannot be read.
	 *
	 * @return void
	 */
	public function testAGroundWithoutRatificationDoesNotChange(): void {
		$item = ['id' => 'item-9', 'meeting' => 'meeting-15-oct'];

		$this->listener([$item], [self::restriction(['ground' => 'ground-plain'])])->handle($this->meetingCloses());
		$this->listener([$item], [self::restriction(['ground' => 'ground-gone'])])->handle($this->meetingCloses());

		self::assertSame([], $this->saved);
	}//end testAGroundWithoutRatificationDoesNotChange()

	/**
	 * Only the closing transition counts, and only items on that meeting.
	 *
	 * @return void
	 */
	public function testOnlyTheCloseOfThatMeetingCounts(): void {
		$item     = ['id' => 'item-9', 'meeting' => 'meeting-15-oct'];
		$listener = $this->listener([$item], [self::restriction()]);

		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(['id' => 'meeting-15-oct', 'lifecycle' => 'closed', 'title' => 'renamed']),
				$this->entity(['id' => 'meeting-15-oct', 'lifecycle' => 'closed'])
			)
		);
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(['id' => 'meeting-other', 'lifecycle' => 'closed']),
				$this->entity(['id' => 'meeting-other', 'lifecycle' => 'opened'])
			)
		);

		self::assertSame([], $this->saved);
	}//end testOnlyTheCloseOfThatMeetingCounts()

	/**
	 * A clerk recording "not ratified" on the item lapses it at once.
	 *
	 * @return void
	 */
	public function testAClerkRecordingNotRatifiedLapsesIt(): void {
		$item  = ['id' => 'item-9', 'meeting' => 'meeting-15-oct', 'ratificationOutcome' => 'not-ratified'];
		$event = new ObjectUpdatedEvent($this->entity($item), $this->entity(['ratificationOutcome' => null] + $item));

		$this->listener([$item], [self::restriction()])->handle($event);

		self::assertSame('lapsed', $this->saved['r-tarieven']['lifecycle']);
		self::assertSame('meeting-15-oct', $this->saved['r-tarieven']['lapsedAtMeeting']);
	}//end testAClerkRecordingNotRatifiedLapsesIt()

	/**
	 * The decision rule on its own, outcome by outcome.
	 *
	 * @return void
	 */
	public function testTheLapseRule(): void {
		$listener = $this->listener([], []);
		$ground   = ['requiresRatification' => true];
		$imposed  = self::restriction();

		self::assertTrue($listener->lapses($imposed, $ground, [], true));
		self::assertTrue($listener->lapses($imposed, $ground, ['ratificationOutcome' => 'not-ratified'], true));
		self::assertFalse($listener->lapses($imposed, $ground, ['ratificationOutcome' => 'postponed'], true));
		self::assertFalse($listener->lapses($imposed, $ground, ['ratificationOutcome' => 'ratified'], true));
		self::assertFalse($listener->lapses($imposed, $ground, [], false));
		self::assertTrue($listener->lapses($imposed, $ground, ['ratificationOutcome' => 'not-ratified'], false));
		self::assertFalse($listener->lapses($imposed, null, [], true));
		self::assertFalse($listener->lapses(self::restriction(['lifecycle' => 'lapsed']), $ground, [], true));
	}//end testTheLapseRule()

	/**
	 * A lapsed decision-scoped restriction no longer keeps the decision from publication.
	 *
	 * @return void
	 */
	public function testALapsedRestrictionNoLongerBlocksPublication(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([$this->entity(self::restriction(['scope' => 'decision', 'targetDecision' => 'decision-1', 'lifecycle' => 'lapsed']))]);

		self::assertFalse((new ConfidentialityRestrictions($objects))->isDecisionRestricted('decision-1'));
	}//end testALapsedRestrictionNoLongerBlocksPublication()
}//end class
