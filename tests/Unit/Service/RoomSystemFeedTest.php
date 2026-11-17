<?php

/**
 * RoomSystemFeed, its client and its listener: the published agenda with its current
 * item, the speakers' list and a closed round's totals go to a linked room system, and
 * nothing goes anywhere while no room system is linked.
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
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Listener\RoomSystemFeedListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\RoomSystemClient;
use OCA\Decidiq\Service\RoomSystemFeed;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * OpenRegister is an in-memory store behind its contract; integriq's call service
 * is the boundary, so every call it receives is asserted.
 *
 * @covers \OCA\Decidiq\Service\RoomSystemFeed
 * @covers \OCA\Decidiq\Service\RoomSystemClient
 * @covers \OCA\Decidiq\Listener\RoomSystemFeedListener
 * @uses   \OCA\Decidiq\Service\IntegriqConnection
 * @uses   \OCA\Decidiq\Support\FleetAppId
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 * @uses \OCA\Decidiq\Service\ObjectRows
 */
final class RoomSystemFeedTest extends TestCase {

	/**
	 * Objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every call integriq's call service received, as [endpoint, method, body].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * The answer integriq's call service gives: a status code, or an exception to throw.
	 *
	 * @var int|RuntimeException
	 */
	private int|RuntimeException $answer = 200;

	/**
	 * Set up a meeting with a published agenda in a linked room.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'meeting'           => [
				'meet-1' => [
					'title'             => 'Raadsvergadering',
					'agendaPublishedAt' => '2026-11-01T09:00:00+01:00',
					'agendaVersion'     => 2,
					'currentAgendaItem' => 'item-visie',
				],
			],
			'agenda-item'       => [
				'item-visie'   => ['meeting' => 'meet-1', 'orderNumber' => 3, 'title' => 'Vaststelling omgevingsvisie'],
				'item-opening' => ['meeting' => 'meet-1', 'orderNumber' => 1, 'title' => 'Opening'],
				'item-notulen' => ['meeting' => 'meet-1', 'orderNumber' => 2, 'title' => 'Notulen'],
				'item-elders'  => ['meeting' => 'meet-2', 'orderNumber' => 1, 'title' => 'Andere vergadering'],
			],
			'participant'       => [
				'p-vries'  => ['displayName' => 'J. de Vries', 'roomSeat' => '12'],
				'p-bakker' => ['displayName' => 'A. Bakker', 'roomSeat' => '14'],
			],
			'engagement-record' => [
				'rec-vries'  => [
					'meeting'   => 'meet-1',
					'participant' => 'p-vries',
					'speeches'  => [
						['startTime' => '2026-11-12T19:50:00+01:00', 'endTime' => '2026-11-12T19:52:00+01:00', 'agendaItem' => 'item-visie'],
					],
				],
				'rec-bakker' => [
					'meeting'   => 'meet-1',
					'participant' => 'p-bakker',
					'speeches'  => [
						['startTime' => '2026-11-12T19:42:00+01:00', 'endTime' => '2026-11-12T19:46:00+01:00', 'agendaItem' => 'item-visie'],
					],
				],
			],
			// The room-system connection, linked to a source in integriq.
			'app_connection'    => ['conn-room' => ['app' => 'decidiq', 'key' => 'room-system', 'source' => 'src-room']],
			'source'            => ['src-room' => ['title' => 'Delegate system']],
		];
	}//end setUp()

	/**
	 * An in-memory OpenRegister behind its contract.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id, ?ObjectFindOptions $options = null): ?ObjectEntityInterface
				=> isset($this->store[(string)$options?->schema][$id]) === true ? $this->entity(id: (string)$id, data: $this->store[(string)$options?->schema][$id]) : null
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
	 * A container whose integriq call service records each call and answers with $this->answer.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$caller = new class($this) {
			/**
			 * Remember the test.
			 *
			 * @param RoomSystemFeedTest $test The test.
			 */
			public function __construct(private readonly RoomSystemFeedTest $test) {
			}//end __construct()

			/**
			 * Record a call and answer it.
			 *
			 * @param object               $source   The source.
			 * @param string               $endpoint The endpoint.
			 * @param string               $method   The HTTP method.
			 * @param array<string, mixed> $config   The request config.
			 *
			 * @return object The answer.
			 */
			public function call(object $source, string $endpoint, string $method, array $config): object {
				return $this->test->received(source: $source, endpoint: $endpoint, method: $method, config: $config);
			}//end call()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($caller);
		return $container;
	}//end container()

	/**
	 * What integriq's call service does with a call: record it, then answer or throw.
	 *
	 * @param object               $source   The source.
	 * @param string               $endpoint The endpoint.
	 * @param string               $method   The HTTP method.
	 * @param array<string, mixed> $config   The request config.
	 *
	 * @return object The answer.
	 */
	public function received(object $source, string $endpoint, string $method, array $config): object {
		self::assertSame('src-room', $source->getUuid());
		self::assertSame(['Content-Type' => 'application/json'], $config['headers']);
		$this->calls[] = [$endpoint, $method, json_decode((string)$config['body'], true)];
		if ($this->answer instanceof RuntimeException) {
			throw $this->answer;
		}

		$status = $this->answer;
		return new class($status) {
			/**
			 * Remember the status.
			 *
			 * @param int $status The status.
			 */
			public function __construct(private readonly int $status) {
			}//end __construct()

			/**
			 * The raw response.
			 *
			 * @return array<string, int> The response.
			 */
			public function getResponse(): array {
				return ['statusCode' => $this->status];
			}//end getResponse()
		};
	}//end received()

	/**
	 * The feed under test.
	 *
	 * @return RoomSystemFeed
	 */
	private function feed(): RoomSystemFeed {
		$objects = $this->objects();
		return new RoomSystemFeed($objects, new RoomSystemClient($objects, $this->container(), new NullLogger()));
	}//end feed()

	/**
	 * The meeting as saved, with its id.
	 *
	 * @param array<string, mixed> $changes Fields that differ from the stored meeting.
	 *
	 * @return array<string, mixed>
	 */
	private function meeting(array $changes = []): array {
		return (['id' => 'meet-1'] + $changes + $this->store['meeting']['meet-1']);
	}//end meeting()

	/**
	 * A round on the meeting.
	 *
	 * @param string|null $closedAt When it closed.
	 *
	 * @return array<string, mixed>
	 */
	private function round(?string $closedAt): array {
		return [
			'id'           => 'round-1',
			'meeting'      => 'meet-1',
			'closedAt'     => $closedAt,
			'votesFor'     => 21,
			'votesAgainst' => 12,
			'votesAbstain' => 2,
			'result'       => 'adopted',
		];
	}//end round()

	/**
	 * When the chair closes a round, the room gets its totals with the current item.
	 *
	 * @return void
	 */
	public function testAClosedRoundSendsItsTotalsWithTheCurrentItem(): void {
		$sent = $this->feed()->roundClosed($this->round(closedAt: '2026-11-12T20:10:00+01:00'), $this->round(closedAt: null));

		self::assertTrue($sent);
		self::assertSame([[
			'/room-system/vote-result',
			'POST',
			[
				'meeting'           => 'meet-1',
				'votingRound'       => 'round-1',
				'currentAgendaItem' => 'item-visie',
				'agendaItemTitle'   => 'Vaststelling omgevingsvisie',
				'votesFor'          => 21,
				'votesAgainst'      => 12,
				'votesAbstain'      => 2,
				'result'            => 'adopted',
			],
		]], $this->calls);
	}//end testAClosedRoundSendsItsTotalsWithTheCurrentItem()

	/**
	 * A round that is still open, or was closed before this save, sends nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheClosingSaveOfARoundSends(): void {
		$feed = $this->feed();

		self::assertFalse($feed->roundClosed($this->round(closedAt: null), []));
		self::assertFalse($feed->roundClosed($this->round(closedAt: ''), $this->round(closedAt: null)));
		self::assertFalse($feed->roundClosed($this->round(closedAt: '2026-11-12T20:10:00+01:00'), $this->round(closedAt: '2026-11-12T20:10:00+01:00')));
		self::assertSame([], $this->calls);
	}//end testOnlyTheClosingSaveOfARoundSends()

	/**
	 * With no room system linked, nothing is sent and nothing fails.
	 *
	 * @return void
	 */
	public function testNothingIsSentWhileNoRoomSystemIsLinked(): void {
		$this->store['app_connection'] = [];
		$feed = $this->feed();

		self::assertFalse($feed->roundClosed($this->round(closedAt: '2026-11-12T20:10:00+01:00'), []));
		self::assertFalse($feed->agendaChanged($this->meeting(), []));
		self::assertFalse($feed->speakersChanged(['id' => 'rec-vries'] + $this->store['engagement-record']['rec-vries'], []));
		self::assertSame([], $this->calls);
	}//end testNothingIsSentWhileNoRoomSystemIsLinked()

	/**
	 * The published agenda goes over with its items in order and the current item.
	 *
	 * @return void
	 */
	public function testAPublishedAgendaSendsItsItemsInOrder(): void {
		$sent = $this->feed()->agendaChanged($this->meeting(), $this->meeting(changes: ['currentAgendaItem' => 'item-notulen']));

		self::assertTrue($sent);
		self::assertSame([[
			'/room-system/agenda',
			'POST',
			[
				'meeting'           => 'meet-1',
				'title'             => 'Raadsvergadering',
				'agendaVersion'     => 2,
				'currentAgendaItem' => 'item-visie',
				'items'             => [
					['id' => 'item-opening', 'orderNumber' => 1, 'title' => 'Opening'],
					['id' => 'item-notulen', 'orderNumber' => 2, 'title' => 'Notulen'],
					['id' => 'item-visie', 'orderNumber' => 3, 'title' => 'Vaststelling omgevingsvisie'],
				],
			],
		]], $this->calls);
	}//end testAPublishedAgendaSendsItsItemsInOrder()

	/**
	 * An unpublished agenda, or a save that changes nothing the room shows, sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnpublishedOrUnchangedAgendaSendsNothing(): void {
		$feed = $this->feed();

		self::assertFalse($feed->agendaChanged($this->meeting(changes: ['agendaPublishedAt' => null]), []));
		self::assertFalse($feed->agendaChanged($this->meeting(changes: ['title' => 'Nieuwe titel']), $this->meeting()));
		self::assertSame([], $this->calls);
	}//end testAnUnpublishedOrUnchangedAgendaSendsNothing()

	/**
	 * A new speech sends the meeting's whole speakers' list, in the order they spoke.
	 *
	 * @return void
	 */
	public function testANewSpeechSendsTheSpeakersListInOrder(): void {
		$record = ['id' => 'rec-vries'] + $this->store['engagement-record']['rec-vries'];
		$before = (['speeches' => []] + $record);

		self::assertTrue($this->feed()->speakersChanged($record, $before));
		self::assertSame([[
			'/room-system/speakers',
			'POST',
			[
				'meeting'  => 'meet-1',
				'speakers' => [
					['participant' => 'p-bakker', 'name' => 'A. Bakker', 'seat' => '14', 'agendaItem' => 'item-visie', 'startTime' => '2026-11-12T19:42:00+01:00', 'endTime' => '2026-11-12T19:46:00+01:00'],
					['participant' => 'p-vries', 'name' => 'J. de Vries', 'seat' => '12', 'agendaItem' => 'item-visie', 'startTime' => '2026-11-12T19:50:00+01:00', 'endTime' => '2026-11-12T19:52:00+01:00'],
				],
			],
		]], $this->calls);
	}//end testANewSpeechSendsTheSpeakersListInOrder()

	/**
	 * A record without a meeting, or whose speeches did not change, sends nothing.
	 *
	 * @return void
	 */
	public function testARecordWithoutNewSpeechesSendsNothing(): void {
		$record = ['id' => 'rec-vries'] + $this->store['engagement-record']['rec-vries'];
		$feed = $this->feed();

		self::assertFalse($feed->speakersChanged($record, $record));
		self::assertFalse($feed->speakersChanged((['meeting' => ''] + $record), []));
		self::assertSame([], $this->calls);
	}//end testARecordWithoutNewSpeechesSendsNothing()

	/**
	 * A room system that refuses or cannot be reached reads as not sent, without an exception.
	 *
	 * @return void
	 */
	public function testARefusedOrUnreachableRoomSystemReadsAsNotSent(): void {
		$closed = $this->round(closedAt: '2026-11-12T20:10:00+01:00');

		$this->answer = 503;
		self::assertFalse($this->feed()->roundClosed($closed, []));

		$this->answer = new RuntimeException('connection refused');
		self::assertFalse($this->feed()->roundClosed($closed, []));

		self::assertCount(2, $this->calls);
	}//end testARefusedOrUnreachableRoomSystemReadsAsNotSent()

	/**
	 * Without integriq's call service, the client sends nothing.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$client = new RoomSystemClient($this->objects(), $container, new NullLogger());

		self::assertTrue($client->isConnected());
		self::assertFalse($client->send('agenda', ['meeting' => 'meet-1']));
	}//end testWithoutIntegriqNothingIsSent()

	/**
	 * The listener passes an updated voting round from the real OpenRegister event, with the
	 * object it replaced, and ignores other schemas.
	 *
	 * @return void
	 */
	public function testTheListenerPassesAClosingRound(): void {
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
				$schema->setSlug(['52' => 'voting-round', '41' => 'agenda-item'][(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$listener = new RoomSystemFeedListener(
			$this->feed(),
			new ListenerSchemaResolver(container: $container, logger: new NullLogger()),
			new NullLogger()
		);

		foreach (['41', '52'] as $schemaId) {
			$new = $this->objectEntity(schemaId: $schemaId, data: $this->round(closedAt: '2026-11-12T20:10:00+01:00'));
			$old = $this->objectEntity(schemaId: $schemaId, data: $this->round(closedAt: null));
			$listener->handle(new ObjectUpdatedEvent($new, $old));
		}

		self::assertCount(1, $this->calls);
		self::assertSame('/room-system/vote-result', $this->calls[0][0]);
		self::assertSame('round-1', $this->calls[0][2]['votingRound']);
	}//end testTheListenerPassesAClosingRound()

	/**
	 * An OpenRegister entity of a schema.
	 *
	 * @param string               $schemaId The schema id.
	 * @param array<string, mixed> $data     The object, with its `id`.
	 *
	 * @return ObjectEntity
	 */
	private function objectEntity(string $schemaId, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)$data['id']);
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		unset($data['id']);
		$entity->setObject($data);
		return $entity;
	}//end objectEntity()
}//end class
