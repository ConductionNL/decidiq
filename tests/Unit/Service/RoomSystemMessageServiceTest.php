<?php

/**
 * RoomSystemMessageService and its listener: a speaker marking becomes a speech, a desk
 * vote a named vote under the voting rules, and anything else a rejected message with a reason.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Kernel\Contracts\EngagementServiceInterface;
use OCA\Decidiq\Listener\RoomSystemMessageListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\RoomSystemMessageService;
use OCA\Decidiq\Service\VoteCast;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * OpenRegister is an in-memory store behind its contract; the engagement and
 * voting services are the collaborators the change reuses, so their calls are asserted.
 *
 * @covers \OCA\Decidiq\Service\RoomSystemMessageService
 * @covers \OCA\Decidiq\Service\RoomSystemRefusal
 * @covers \OCA\Decidiq\Listener\RoomSystemMessageListener
 * @uses   \OCA\Decidiq\Service\IntegriqConnection
 */
final class RoomSystemMessageServiceTest extends TestCase {

	/**
	 * Objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every save, as [schema, id, object].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * The engagement service.
	 *
	 * @var EngagementServiceInterface&MockObject
	 */
	private EngagementServiceInterface&MockObject $engagement;

	/**
	 * The voting service.
	 *
	 * @var VoteCastingService&MockObject
	 */
	private VoteCastingService&MockObject $votes;

	/**
	 * Set up the meeting, its body's members and the rounds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'meeting'     => ['meet-1' => ['title' => 'Raadsvergadering', 'governanceBody' => 'raad']],
			'participant' => [
				'p-vries'  => ['displayName' => 'J. de Vries', 'roomSeat' => '12', 'governanceBody' => 'raad'],
				'p-bakker' => ['displayName' => 'A. Bakker', 'roomSeat' => '14', 'governanceBody' => 'raad'],
				'p-twin1'  => ['displayName' => 'B. Een', 'roomSeat' => '30', 'governanceBody' => 'raad'],
				'p-twin2'  => ['displayName' => 'B. Twee', 'roomSeat' => '30', 'governanceBody' => 'raad'],
				'p-other'  => ['displayName' => 'Elders', 'roomSeat' => '27', 'governanceBody' => 'staten'],
			],
			'voting-round' => [
				'round-open'   => ['isSecret' => false],
				'round-secret' => ['isSecret' => true],
			],
			'vote'                => [],
			'room-system-message' => [],
			// The room-system connection, linked to a source in integriq.
			'app_connection'      => ['conn-room' => ['app' => 'decidiq', 'key' => 'room-system', 'source' => 'src-room']],
			'source'              => ['src-room' => ['title' => 'Delegate system']],
		];
		$this->engagement = $this->createMock(EngagementServiceInterface::class);
		$this->votes = $this->createMock(VoteCastingService::class);
	}//end setUp()

	/**
	 * An in-memory OpenRegister behind its contract.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntityInterface
				=> isset($this->store[$schema][$id]) === true ? $this->entity(id: (string)$id, data: $this->store[$schema][$id]) : null
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->store[$schema] ?? []) as $id => $data) {
					if (array_intersect_assoc(array_map('strval', $filters), array_map('strval', array_intersect_key($data, $filters))) === array_map('strval', $filters)) {
						$found[] = $this->entity(id: (string)$id, data: $data);
					}
				}

				return $found;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->store[$schema][$uuid] = $object;
				$this->saves[] = [$schema, $uuid, $object];
				return $this->entity(id: (string)$uuid, data: $object);
			}
		);
		return $objects;
	}//end objects()

	/**
	 * An entity as the contract returns it.
	 *
	 * @param string               $id   The UUID.
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $id, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($id);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The service under test.
	 *
	 * @return RoomSystemMessageService
	 */
	private function service(): RoomSystemMessageService {
		return new RoomSystemMessageService($this->objects(), $this->engagement, $this->votes, new NullLogger());
	}//end service()

	/**
	 * A message as integriq stores it.
	 *
	 * @param string               $id     The message UUID.
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return array<string, mixed>
	 */
	private function message(string $id, array $fields): array {
		$message = ($fields + ['meeting' => 'meet-1', 'status' => 'received', 'externalId' => 'mvi-' . $id]);
		$this->store['room-system-message'][$id] = $message;
		return (['id' => $id] + $message);
	}//end message()

	/**
	 * A desk vote from a seat.
	 *
	 * @param string $id    The message UUID.
	 * @param string $seat  The seat.
	 * @param string $round The round.
	 *
	 * @return array<string, mixed>
	 */
	private function deskVote(string $id, string $seat, string $round = 'round-open'): array {
		return $this->message(id: $id, fields: ['kind' => 'vote', 'seat' => $seat, 'votingRound' => $round, 'value' => 'for']);
	}//end deskVote()

	/**
	 * A speaker marking on an agenda item becomes a speech on the member's record.
	 *
	 * @return void
	 */
	public function testAMemberSpeaksOnAnAgendaItem(): void {
		$this->engagement->expects(self::once())->method('captureEngagement')->with(
			'meet-1',
			'p-vries',
			'speech',
			['startTime' => '2026-11-12T19:42:00+01:00', 'endTime' => '2026-11-12T19:46:00+01:00', 'agendaItem' => 'item-visie', 'source' => 'room-system']
		)->willReturn([]);

		$result = $this->service()->apply($this->message(id: 'm1', fields: [
			'kind'       => 'speech',
			'seat'       => '12',
			'agendaItem' => 'item-visie',
			'startedAt'  => '2026-11-12T19:42:00+01:00',
			'endedAt'    => '2026-11-12T19:46:00+01:00',
		]));

		self::assertSame('applied', $result['status']);
		self::assertSame('p-vries', $result['participant']);
		self::assertNull($result['rejectReason']);
	}//end testAMemberSpeaksOnAnAgendaItem()

	/**
	 * A desk vote counts through castVote() and is marked as cast in person through the room system.
	 *
	 * @return void
	 */
	public function testADeskVoteCounts(): void {
		$this->votes->expects(self::once())->method('castVote')->willReturnCallback(
			function (VoteCast $cast): array {
				self::assertSame('round-open', $cast->votingRoundId);
				self::assertSame('p-bakker', $cast->participantId);
				self::assertSame('for', $cast->value());
				$this->store['vote']['vote-1'] = ['value' => 'for', 'castAs' => 'unknown', 'votingRound' => 'round-open'];
				return ['id' => 'vote-1', 'value' => 'for'];
			}
		);

		$result = $this->service()->apply($this->deskVote(id: 'm2', seat: '14'));

		self::assertSame('applied', $result['status']);
		self::assertSame('p-bakker', $result['participant']);
		self::assertSame(
			['castAs' => 'in-person', 'castVia' => 'room-system', 'value' => 'for', 'votingRound' => 'round-open'],
			$this->store['vote']['vote-1']
		);
	}//end testADeskVoteCounts()

	/**
	 * A secret ballot is never read from the desks.
	 *
	 * @return void
	 */
	public function testASecretBallotIsNotReadFromTheDesk(): void {
		$this->votes->expects(self::never())->method('castVote');

		$result = $this->service()->apply($this->deskVote(id: 'm3', seat: '14', round: 'round-secret'));

		self::assertSame('rejected', $result['status']);
		self::assertSame('A secret ballot cannot be read from the desk units', $result['rejectReason']);
		self::assertSame([], $this->store['vote']);
	}//end testASecretBallotIsNotReadFromTheDesk()

	/**
	 * The same external id twice in a meeting counts once.
	 *
	 * @return void
	 */
	public function testTheSameMessageTwiceCountsOnce(): void {
		$this->store['room-system-message']['m-first'] = ['meeting' => 'meet-1', 'externalId' => 'mvi-8812', 'status' => 'applied'];
		$this->votes->expects(self::never())->method('castVote');

		$again = $this->message(id: 'm4', fields: ['kind' => 'vote', 'seat' => '14', 'votingRound' => 'round-open', 'value' => 'for', 'externalId' => 'mvi-8812']);
		$result = $this->service()->apply($again);

		self::assertSame('rejected', $result['status']);
		self::assertSame('Already applied', $result['rejectReason']);
	}//end testTheSameMessageTwiceCountsOnce()

	/**
	 * A seat of no member of the meeting's body, or of two, is rejected with the seat named.
	 *
	 * @return void
	 */
	public function testAnUnlinkedOrSharedSeatIsRejected(): void {
		$this->votes->expects(self::never())->method('castVote');

		// Seat 27 belongs to a member of another body.
		$unlinked = $this->service()->apply($this->deskVote(id: 'm5', seat: '27'));
		$shared = $this->service()->apply($this->deskVote(id: 'm6', seat: '30'));

		self::assertSame(['rejected', 'Seat 27 is not linked to a member', null], [$unlinked['status'], $unlinked['rejectReason'], $unlinked['participant']]);
		self::assertSame(['rejected', 'Seat 30 is linked to more than one member'], [$shared['status'], $shared['rejectReason']]);
	}//end testAnUnlinkedOrSharedSeatIsRejected()

	/**
	 * A voting rule's refusal is the reason, and the message can be applied again once the cause is fixed.
	 *
	 * @return void
	 */
	public function testARefusedVoteIsKeptAndAppliedAgain(): void {
		$this->votes->expects(self::exactly(2))->method('castVote')->willReturnOnConsecutiveCalls(
			self::throwException(new RuntimeException('The voting round is not open')),
			['id' => '']
		);
		$service = $this->service();

		$first = $service->apply($this->deskVote(id: 'm7', seat: '12'));
		$again = $service->applyById('m7');

		self::assertSame(['rejected', 'The voting round is not open'], [$first['status'], $first['rejectReason']]);
		self::assertSame(['applied', null, 'p-vries'], [$again['status'], $again['rejectReason'], $again['participant']]);
		self::assertNull($service->applyById('nope'));
	}//end testARefusedVoteIsKeptAndAppliedAgain()

	/**
	 * An applied message is left alone: nothing is cast or written twice.
	 *
	 * @return void
	 */
	public function testAnAppliedMessageIsLeftAlone(): void {
		$this->votes->expects(self::never())->method('castVote');
		$message = ['id' => 'm8', 'status' => 'applied', 'kind' => 'vote', 'seat' => '14'];

		self::assertSame($message, $this->service()->apply($message));
		self::assertSame([], $this->saves);
	}//end testAnAppliedMessageIsLeftAlone()

	/**
	 * While no room system is linked, nothing is applied: the message is rejected with the reason the page shows.
	 *
	 * @return void
	 */
	public function testNothingIsAppliedWhileNoRoomSystemIsLinked(): void {
		$this->store['app_connection']['conn-room']['source'] = '';
		$this->votes->expects(self::never())->method('castVote');
		$service = $this->service();

		$result = $service->apply($this->deskVote(id: 'm20', seat: '12'));

		self::assertFalse($service->isConnected());
		self::assertSame(['rejected', 'No room system is connected', null], [$result['status'], $result['rejectReason'], $result['participant']]);
	}//end testNothingIsAppliedWhileNoRoomSystemIsLinked()

	/**
	 * A meeting's messages read rejected ones first, and a message names its meeting.
	 *
	 * @return void
	 */
	public function testAMeetingsMessagesListRejectedOnesFirst(): void {
		$this->message(id: 'm21', fields: ['kind' => 'speech', 'seat' => '12', 'status' => 'applied']);
		$this->message(id: 'm22', fields: ['kind' => 'vote', 'seat' => '27', 'status' => 'rejected']);
		$this->message(id: 'm23', fields: ['kind' => 'vote', 'seat' => '14', 'meeting' => 'meet-2', 'status' => 'rejected']);
		$service = $this->service();

		self::assertTrue($service->isConnected());
		self::assertSame(['m22', 'm21'], array_column($service->forMeeting('meet-1'), 'id'));
		self::assertSame([], $service->forMeeting(''));
		self::assertSame('meet-2', $service->meetingOf('m23'));
		self::assertNull($service->meetingOf('nope'));
	}//end testAMeetingsMessagesListRejectedOnesFirst()

	/**
	 * The listener applies a message from the real OpenRegister created event, and ignores other schemas.
	 *
	 * @return void
	 */
	public function testTheListenerAppliesACreatedMessage(): void {
		$mapper = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug(['61' => 'room-system-message', '41' => 'agenda-item'][(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$this->engagement->expects(self::once())->method('captureEngagement')->willReturn([]);
		$listener = new RoomSystemMessageListener(
			$this->service(),
			new ListenerSchemaResolver(container: $container, logger: new NullLogger()),
			new NullLogger()
		);

		$fields = ['meeting' => 'meet-1', 'kind' => 'speech', 'seat' => '12', 'externalId' => 'mvi-1', 'status' => 'received'];
		foreach (['41', '61'] as $schemaId) {
			$entity = new ObjectEntity();
			$entity->setUuid('msg-' . $schemaId);
			$entity->setRegister('7');
			$entity->setSchema($schemaId);
			$entity->setObject($fields);
			$listener->handle(new ObjectCreatedEvent($entity));
		}

		self::assertSame('applied', $this->store['room-system-message']['msg-61']['status']);
		self::assertArrayNotHasKey('msg-41', $this->store['room-system-message']);
	}//end testTheListenerAppliesACreatedMessage()
}//end class
