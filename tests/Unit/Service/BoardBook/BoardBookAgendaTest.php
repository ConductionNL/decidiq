<?php

/**
 * Unit tests for BoardBookAgenda and BoardBookJobState.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\BoardBook;

use OCA\Decidiq\Service\BoardBook\BoardBookAgenda;
use OCA\Decidiq\Service\BoardBook\BoardBookJobState;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\ICache;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What a pack is compiled from, and where a compilation stands.
 */
#[CoversClass(BoardBookAgenda::class)]
#[CoversClass(BoardBookJobState::class)]
class BoardBookAgendaTest extends TestCase {

	/**
	 * Only the meeting's own rows, items by orderNumber, packs newest first, attachments as rows.
	 *
	 * @return void
	 */
	public function testReadsTheMeetingsOwnRowsInOrder(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $query): array => match ($query['filters']['schema'] ?? null) {
				'agenda-item' => [
					['id' => 'b', 'orderNumber' => 2, 'meeting' => 'm1'],
					['id' => 'x', 'orderNumber' => 1, 'meeting' => 'other'],
					['id' => 'a', 'orderNumber' => 1, 'meeting' => ['id' => 'm1']],
					['id' => 'n', 'orderNumber' => 0],
				],
				'meeting-pack' => [['@self' => ['id' => 'p1'], 'version' => 1, 'meeting' => 'm1'], ['id' => 'p2', 'version' => 2, 'meeting' => 'm1']],
				default => [],
			}
		);
		$file = new class {
			/** @return int */
			public function getId(): int {
				return 41;
			}
			/** @return string */
			public function getName(): string {
				return 'stuk.pdf';
			}
			/** @return string */
			public function getEtag(): string {
				return 'etag-1';
			}
			/** @return string */
			public function getMimetype(): string {
				return 'application/pdf';
			}
			/** @return int */
			public function getSize(): int {
				return 1234;
			}
		};
		$files = new class($file) {
			/** @param object $file The file. */
			public function __construct(private object $file) {
			}
			/**
			 * @param string $id The object id.
			 * @return array<int, object>
			 */
			public function getFiles(string $id): array {
				return $id === 'a' ? [$this->file, 'not a node'] : [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($files);
		$agenda = new BoardBookAgenda(objectService: $objects, container: $container, logger: $this->createMock(LoggerInterface::class));

		$items = $agenda->items(meetingId: 'm1');
		$this->assertSame(expected: ['a', 'b'], actual: array_column($items, 'id'));
		$this->assertSame(expected: ['p2', 'p1'], actual: array_column($agenda->packs(meetingId: 'm1'), 'id'));
		$attachments = $agenda->attachments(items: $items);
		$this->assertSame(expected: ['a' => [['id' => '41', 'name' => 'stuk.pdf', 'etag' => 'etag-1', 'mime' => 'application/pdf', 'size' => 1234]], 'b' => []], actual: $attachments['rows']);
		$this->assertSame(expected: [$file], actual: $attachments['nodes']['a']);
	}//end testReadsTheMeetingsOwnRowsInOrder()

	/**
	 * Every read names its register and schema inside `filters`, where
	 * OpenRegister reads them; at the top level the read was not scoped.
	 *
	 * @return void
	 */
	public function testTheLinkedReadsAreScopedInTheFilters(): void {
		$queries = [];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $query) use (&$queries): array {
				$queries[] = $query;
				return [];
			}
		);
		$agenda = new BoardBookAgenda(
			objectService: $objects,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$agenda->items(meetingId: 'm1');
		$agenda->packs(meetingId: 'm1');

		$this->assertSame(
			expected: [
				['filters' => ['register' => 'decidiq', 'schema' => 'agenda-item', 'meeting' => 'm1'], 'limit' => 500],
				['filters' => ['register' => 'decidiq', 'schema' => 'meeting-pack', 'meeting' => 'm1'], 'limit' => 500],
			],
			actual: $queries
		);
	}//end testTheLinkedReadsAreScopedInTheFilters()

	/**
	 * The meeting and its body are read through the find options, and the
	 * pack is saved through the save options, on the real contract signature.
	 *
	 * @return void
	 */
	public function testTheMeetingIsReadAndThePackSavedThroughTheContract(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setObject(
					match ([(string)$options?->schema, (string)$id]) {
						['meeting', 'm1'] => ['title' => 'Raadsvergadering', 'governanceBody' => 'raad'],
						['governance-body', 'raad'] => ['name' => 'Gemeenteraad'],
					}
				);
				self::assertSame(expected: 'decidiq', actual: $options?->register);
				return $entity;
			}
		);
		$saved = [];
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (&$saved): ObjectEntity {
				$saved[] = [$options->target->register, $options->target->schema, $object];
				$entity = new ObjectEntity();
				$entity->setObject($object + ['id' => 'pack-3']);
				return $entity;
			}
		);
		$agenda = new BoardBookAgenda(
			objectService: $objects,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$meeting = $agenda->meeting(meetingId: 'm1');
		$this->assertEquals(expected: ['title' => 'Raadsvergadering', 'governanceBody' => 'raad', 'id' => 'm1'], actual: $meeting);
		$this->assertSame(expected: 'Gemeenteraad', actual: $agenda->bodyName(meeting: $meeting));

		$agenda->savePack(pack: ['meeting' => 'm1', 'version' => 3]);
		$this->assertSame(expected: [['decidiq', 'meeting-pack', ['meeting' => 'm1', 'version' => 3]]], actual: $saved);
	}//end testTheMeetingIsReadAndThePackSavedThroughTheContract()

	/**
	 * A state round-trips; nothing recorded reads idle; queued and running are in flight.
	 *
	 * @return void
	 */
	public function testTheJobStateRoundTrips(): void {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$store): mixed {
				return ($store[$key] ?? null);
			}
		);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$caches->method('isAvailable')->willReturn(true);
		$state = new BoardBookJobState(caches: $caches, appConfig: $this->createMock(originalClassName: IAppConfig::class));

		$this->assertSame(expected: ['state' => 'idle', 'outcome' => []], actual: $state->get(meetingId: 'm1'));
		$this->assertFalse(condition: $state->inFlight(meetingId: 'm1'));
		$state->set(meetingId: 'm1', state: 'queued');
		$this->assertTrue(condition: $state->inFlight(meetingId: 'm1'));
		$state->set(meetingId: 'm1', state: 'done', outcome: ['version' => 2]);
		$this->assertSame(expected: ['state' => 'done', 'outcome' => ['version' => 2]], actual: $state->get(meetingId: 'm1'));
		$this->assertFalse(condition: $state->inFlight(meetingId: 'm1'));
	}//end testTheJobStateRoundTrips()

	/**
	 * Without a distributed cache the state is kept in the app config, which
	 * the cron job and the web request share, and an entry older than a day
	 * reads as idle.
	 *
	 * @return void
	 */
	public function testWithoutADistributedCacheTheStateLivesInTheAppConfig(): void {
		$store  = [];
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use (&$store): void {
				unset($store[$key]);
			}
		);
		$caches = $this->createMock(originalClassName: ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($this->createMock(originalClassName: ICache::class));
		$caches->method('isAvailable')->willReturn(false);

		// The cron job writes with one instance, the web request reads with another.
		(new BoardBookJobState(caches: $caches, appConfig: $config))->set(meetingId: 'm1', state: 'done', outcome: ['version' => 1]);
		$reader = new BoardBookJobState(caches: $caches, appConfig: $config);
		$this->assertSame(expected: ['state' => 'done', 'outcome' => ['version' => 1]], actual: $reader->get(meetingId: 'm1'));

		$store['board_book_state_m1'] = (string)json_encode(['state' => 'running', 'outcome' => [], 'at' => time() - BoardBookJobState::TTL - 1]);
		$this->assertSame(expected: ['state' => 'idle', 'outcome' => []], actual: $reader->get(meetingId: 'm1'));
		$this->assertArrayNotHasKey(key: 'board_book_state_m1', array: $store);
	}//end testWithoutADistributedCacheTheStateLivesInTheAppConfig()
}//end class
