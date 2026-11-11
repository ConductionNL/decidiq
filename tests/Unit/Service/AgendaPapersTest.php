<?php

/**
 * Unit tests for publishing the papers with the agenda (publication-papers-and-search).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\Decidiq\Service\AgendaPapers;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins AgendaPapers directly: which restrictions count, which papers are
 * published or skipped, which refs are taken offline, and every log line.
 */
class AgendaPapersTest extends TestCase {

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Calls the fake FileService received.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build AgendaPapers over a findAll answer and a FileService (or a container failure).
	 *
	 * @param mixed       $rows        What findAll returns, or a Throwable to throw.
	 * @param object|null $fileService The FileService, or null when the container throws.
	 *
	 * @return AgendaPapers
	 */
	private function papers(mixed $rows=[], ?object $fileService=null): AgendaPapers {
		$this->warnings = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true) use ($rows) {
				$this->calls[] = 'findAll:' . json_encode([$config, $_rbac, $_multitenancy]);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($fileService): object {
				if ($fileService === null) {
					throw new RuntimeException('no FileService');
				}

				return $fileService;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new AgendaPapers($objects, $container, $logger);
	}//end papers()

	/**
	 * A fake FileService over files by node id; ids in $failPublish throw on publishFile.
	 *
	 * @param array<int, array<string, mixed>> $files       Formatted files by node id.
	 * @param array<int, int>                  $failPublish Node ids whose publish throws.
	 * @param bool                             $failList    Whether getFiles throws.
	 *
	 * @return object
	 */
	private function fileService(array $files, array $failPublish=[], bool $failList=false): object {
		$test = $this;

		return new class ($files, $failPublish, $failList, $test) {
			/**
			 * @param array<int, array<string, mixed>> $files       Files.
			 * @param array<int, int>                  $failPublish Failing ids.
			 * @param bool                             $failList    Whether listing fails.
			 * @param AgendaPapersTest                 $test        The test, for call recording.
			 */
			public function __construct(private array $files, private array $failPublish, private bool $failList, private AgendaPapersTest $test) {
			}

			/**
			 * @param string $itemId Item.
			 *
			 * @return array<int, mixed>
			 */
			public function getFiles(string $itemId): array {
				if ($this->failList === true) {
					throw new RuntimeException('list failed');
				}

				$nodes = ['not-a-node'];
				foreach (array_keys($this->files) as $id) {
					$nodes[] = new class ($id) {
						/**
						 * @param int $id Node id.
						 */
						public function __construct(private int $id) {
						}

						/**
						 * @return int
						 */
						public function getId(): int {
							return $this->id;
						}
					};
				}

				return $nodes;
			}

			/**
			 * @param object $node Node or published node.
			 *
			 * @return array<string, mixed>
			 */
			public function formatFile(object $node): array {
				return $this->files[$node->getId()];
			}

			/**
			 * @param string $itemId Item.
			 * @param int    $fileId File.
			 *
			 * @return object
			 */
			public function publishFile(string $itemId, int $fileId): object {
				$this->test->record('publish:' . $itemId . '/' . $fileId);
				if (in_array($fileId, $this->failPublish, true) === true) {
					throw new RuntimeException('publish failed');
				}

				return new class ($fileId) {
					/**
					 * @param int $id Node id.
					 */
					public function __construct(private int $id) {
					}

					/**
					 * @return int
					 */
					public function getId(): int {
						return $this->id;
					}
				};
			}

			/**
			 * @param string $itemId Item.
			 * @param int    $fileId File.
			 *
			 * @return void
			 */
			public function unpublishFile(string $itemId, int $fileId): void {
				$this->test->record('unpublish:' . $itemId . '/' . $fileId);
				if ($fileId === 99) {
					throw new RuntimeException('unpublish failed');
				}
			}
		};
	}//end fileService()

	/**
	 * Record a FileService call.
	 *
	 * @param string $call The call.
	 *
	 * @return void
	 */
	public function record(string $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Only active item restrictions with a target count; wrapped and flat answers both read.
	 *
	 * @return void
	 */
	public function testRestrictedItemIds(): void {
		$entity = new class {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['lifecycle' => 'ratified', 'targetAgendaItem' => 'item-2'];
			}
		};
		$rows = [
			['lifecycle' => 'imposed', 'targetAgendaItem' => 'item-1'],
			$entity,
			['lifecycle' => 'lifted', 'targetAgendaItem' => 'item-3'],
			['lifecycle' => 'imposed', 'targetAgendaItem' => ''],
			['lifecycle' => 'imposed'],
			'junk',
			new \stdClass(),
		];

		$this->calls = [];
		$this->assertSame(['item-1' => true, 'item-2' => true], $this->papers(rows: ['results' => $rows])->restrictedItemIds());
		$this->assertSame(
			['findAll:' . json_encode([['filters' => ['register' => 'decidiq', 'schema' => 'confidentiality-restriction', 'scope' => 'item']], false, false])],
			$this->calls
		);
		$this->assertSame(['item-1' => true, 'item-2' => true], $this->papers(rows: $rows)->restrictedItemIds());
		$this->assertSame([], $this->papers(rows: [])->restrictedItemIds());
	}//end testRestrictedItemIds()

	/**
	 * Unreadable restrictions stop the publication with their own exception.
	 *
	 * @return void
	 */
	public function testUnreadableRestrictionsThrow(): void {
		$cause = new RuntimeException('db down');
		try {
			$this->papers(rows: $cause)->restrictedItemIds();
			$this->fail('expected an exception');
		} catch (ConfidentialityUnreadableException $e) {
			$this->assertSame('The agenda was not published: the confidential items could not be checked. Try again in a moment.', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testUnreadableRestrictionsThrow()

	/**
	 * Public papers are published; confidential, unpublishable and non-node entries are skipped.
	 *
	 * @return void
	 */
	public function testPublishPublishesThePublicPapers(): void {
		$files = [
			1 => ['title' => 'Agenda', 'downloadUrl' => 'https://x/1', 'type' => 'application/pdf'],
			2 => ['title' => 'Geheim stuk', 'labels' => ['Vertrouwelijk']],
			3 => ['title' => 'Kapot'],
			4 => [],
		];
		$this->calls = [];
		$result = $this->papers(fileService: $this->fileService(files: $files, failPublish: [3]))->publish('item-1');

		$this->assertSame(
			[
				'papers' => [
					['title' => 'Agenda', 'url' => 'https://x/1', 'type' => 'application/pdf'],
					['title' => '', 'url' => '', 'type' => ''],
				],
				'refs' => [
					['agendaItem' => 'item-1', 'file' => 1],
					['agendaItem' => 'item-1', 'file' => 4],
				],
			],
			$result
		);
		$this->assertSame(['publish:item-1/1', 'publish:item-1/3', 'publish:item-1/4'], $this->calls);
		$this->assertSame(
			[['Decidiq publication: a paper could not be made public', ['item' => 'item-1', 'exception' => 'publish failed']]],
			$this->warnings
		);
	}//end testPublishPublishesThePublicPapers()

	/**
	 * No FileService, no item id or an unlistable item publishes nothing.
	 *
	 * @return void
	 */
	public function testPublishWithoutFilesPublishesNothing(): void {
		$empty = ['papers' => [], 'refs' => []];

		$this->assertSame($empty, $this->papers()->publish('item-1'));
		$this->assertSame([['Decidiq publication: FileService unavailable, agenda published without papers', ['exception' => 'no FileService']]], $this->warnings);

		$this->assertSame($empty, $this->papers(fileService: new \stdClass())->publish('item-1'));
		$this->assertSame([], $this->warnings);

		$this->assertSame($empty, $this->papers(fileService: $this->fileService(files: [1 => []]))->publish(''));

		$this->assertSame($empty, $this->papers(fileService: $this->fileService(files: [1 => []], failList: true))->publish('item-1'));
		$this->assertSame(
			[['Decidiq publication: papers of an agenda item could not be listed', ['item' => 'item-1', 'exception' => 'list failed']]],
			$this->warnings
		);
	}//end testPublishWithoutFilesPublishesNothing()

	/**
	 * Unpublish skips kept and malformed refs and counts the failures.
	 *
	 * @return void
	 */
	public function testUnpublishCountsFailures(): void {
		$refs = [
			['agendaItem' => 'item-1', 'file' => 1],
			['agendaItem' => 'item-1', 'file' => 2],
			'junk',
			['agendaItem' => 'item-2', 'file' => 99],
		];
		$keep = [['agendaItem' => 'item-1', 'file' => '2'], 'junk'];

		$this->calls = [];
		$this->assertSame(1, $this->papers(fileService: $this->fileService(files: []))->unpublish($refs, $keep));
		$this->assertSame(['unpublish:item-1/1', 'unpublish:item-2/99'], $this->calls);
		$this->assertSame(
			[['Decidiq publication: a paper could not be taken offline', ['ref' => ['agendaItem' => 'item-2', 'file' => 99], 'exception' => 'unpublish failed']]],
			$this->warnings
		);

		$this->assertSame(3, $this->papers()->unpublish($refs));
		$this->assertCount(4, $this->warnings, 'one FileService warning plus one per ref');
		$this->assertSame('OpenRegister FileService unavailable', $this->warnings[3][1]['exception']);
	}//end testUnpublishCountsFailures()
}//end class
