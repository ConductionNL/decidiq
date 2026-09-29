<?php

/**
 * Unit tests for MeetingDefaultsListener.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\MeetingDefaultsListener;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A new meeting takes the defaults of its meeting type, then of its body.
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
 */
class MeetingDefaultsListenerTest extends TestCase {

	private const TYPE = '5d7e9a10-2b3c-4d5e-8f60-718293a4b5c6';

	private const BODY = '0b6c1d2e-3f40-4a51-8b62-7c83d94ea5f6';

	/**
	 * The Commissie meeting type: 90 minutes and quorum 5.
	 *
	 * @var array<string, mixed>
	 */
	private const COMMISSIE = [
		'name' => 'Commissie',
		'governanceBody' => self::BODY,
		'defaultQuorum' => 5,
		'defaultDurationMinutes' => 90,
		'initialLifecycle' => 'scheduled',
		'isPublic' => true,
	];

	/**
	 * Build the listener over an in-memory store.
	 *
	 * @param array<string, array<string, mixed>> $store Objects by id
	 *
	 * @return MeetingDefaultsListener
	 */
	private function listener(array $store): MeetingDefaultsListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($store[(string)$id]) === true ? $this->entity($store[(string)$id]) : null
		);

		return new MeetingDefaultsListener(logger: new NullLogger(), objectService: $objectService);

	}//end listener()

	/**
	 * An OR entity double that serialises to the given row.
	 *
	 * @param array<string, mixed> $row Payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;

	}//end entity()

	/**
	 * A meeting create event as OpenRegister dispatches it: the schema
	 * defaults (lifecycle draft, isPublic false) are already applied.
	 *
	 * @param array<string, mixed> $row The meeting
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $row): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			$this->entity(array_merge(['_schemaSlug' => 'meeting', 'lifecycle' => 'draft', 'isPublic' => false], $row))
		);

	}//end creating()

	/**
	 * Scenario: the clerk plans a committee meeting without entering the
	 * duration or the quorum; the type fills them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	public function testTheClerkPlansACommitteeMeeting(): void {
		$this->assertValid(schema: 'MeetingType', data: self::COMMISSIE);

		$event = $this->creating([
			'title' => 'Commissie Ruimte',
			'meetingType' => 'committee',
			'meetingMode' => 'in-person',
			'type' => self::TYPE,
			'scheduledDate' => '2026-10-06T19:30:00+02:00',
		]);

		$this->listener([self::TYPE => self::COMMISSIE])->handle($event);

		$patch = $event->getModifiedData();
		self::assertSame(5, $patch['quorumRequired']);
		self::assertSame('2026-10-06T21:00:00+02:00', $patch['endDate']);
		self::assertSame('scheduled', $patch['lifecycle']);
		self::assertSame(self::BODY, $patch['governanceBody']);
		self::assertFalse($event->isPropagationStopped());
		$this->assertValid(schema: 'Meeting', data: $patch);

	}//end testTheClerkPlansACommitteeMeeting()

	/**
	 * What the clerk entered is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	public function testEnteredValuesAreKept(): void {
		$event = $this->creating([
			'type' => self::TYPE,
			'scheduledDate' => '2026-10-06T19:30:00+02:00',
			'endDate' => '2026-10-06T20:00:00+02:00',
			'quorumRequired' => 3,
			'lifecycle' => 'scheduled',
			'governanceBody' => 'another-body',
		]);

		$this->listener([self::TYPE => self::COMMISSIE])->handle($event);

		self::assertSame([], $event->getModifiedData());

	}//end testEnteredValuesAreKept()

	/**
	 * Where the type is silent, the body's quorum fills the meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	public function testTheBodyFillsWhatTheTypeLeavesOpen(): void {
		$event = $this->creating(['type' => self::TYPE]);

		$this->listener([
			self::TYPE => ['name' => 'Raad', 'governanceBody' => self::BODY],
			self::BODY => ['name' => 'Gemeenteraad', 'quorum' => 19],
		])->handle($event);

		self::assertSame(['governanceBody' => self::BODY, 'quorumRequired' => 19], $event->getModifiedData());

	}//end testTheBodyFillsWhatTheTypeLeavesOpen()

	/**
	 * A meeting without a type, or of an unknown type, is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	public function testAMeetingWithoutATypeIsLeftAlone(): void {
		$none = $this->creating(['title' => 'Losse bijeenkomst']);
		$unknown = $this->creating(['type' => 'gone']);

		$listener = $this->listener([]);
		$listener->handle($none);
		$listener->handle($unknown);

		self::assertSame([], $none->getModifiedData());
		self::assertSame([], $unknown->getModifiedData());

	}//end testAMeetingWithoutATypeIsLeftAlone()

	/**
	 * The listener is subscribed to meeting creates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	public function testRegistrarSubscribesTheListenerToMeetingCreates(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, MeetingDefaultsListener::class], $subscribed);

	}//end testRegistrarSubscribesTheListenerToMeetingCreates()

	/**
	 * The data validates against the merged schema's properties.
	 *
	 * @param string               $schema The schema name
	 * @param array<string, mixed> $data   The data
	 *
	 * @return void
	 */
	private function assertValid(string $schema, array $data): void {
		$properties = array_map(
			static function (array $property): array {
				unset($property['$ref']);
				return $property;
			},
			SettingsService::shippedRegisterDescriptor()['components']['schemas'][$schema]['properties']
		);

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'additionalProperties' => false]))
		);
		$this->assertTrue($result->isValid(), $schema . ' must accept the data: ' . json_encode($result->error()?->args()));

	}//end assertValid()
}//end class
