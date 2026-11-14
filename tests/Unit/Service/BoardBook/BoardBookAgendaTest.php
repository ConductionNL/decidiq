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
use OCP\ICache;
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
			static fn (array $query): array => match ($query['schema']) {
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
		$state = new BoardBookJobState(caches: $caches);

		$this->assertSame(expected: ['state' => 'idle', 'outcome' => []], actual: $state->get(meetingId: 'm1'));
		$this->assertFalse(condition: $state->inFlight(meetingId: 'm1'));
		$state->set(meetingId: 'm1', state: 'queued');
		$this->assertTrue(condition: $state->inFlight(meetingId: 'm1'));
		$state->set(meetingId: 'm1', state: 'done', outcome: ['version' => 2]);
		$this->assertSame(expected: ['state' => 'done', 'outcome' => ['version' => 2]], actual: $state->get(meetingId: 'm1'));
		$this->assertFalse(condition: $state->inFlight(meetingId: 'm1'));
	}//end testTheJobStateRoundTrips()
}//end class
