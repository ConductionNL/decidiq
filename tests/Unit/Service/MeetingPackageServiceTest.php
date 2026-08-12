<?php

/**
 * Unit tests for MeetingPackageService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingFolderService;
use OCA\Decidesk\Service\MeetingPackageService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the meeting document package assembly (vergaderstukken).
 *
 * The Files layer is faked with an \ArrayObject sink: every written file
 * lands as "<folder path>/<file name>" => content, so assertions can pin
 * the exact package structure.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
class MeetingPackageServiceTest extends TestCase {

	/**
	 * Build the service with in-memory meetings, items, and file fakes.
	 *
	 * @param array<string, array<string, mixed>> $meetings Map of meetingId => meeting row
	 * @param array<int, array<string, mixed>> $items Agenda item rows returned by findAll
	 * @param array<string, array<int, object>> $itemFiles Map of itemId => file nodes
	 * @param \ArrayObject $written Written-files sink ("folder/file" => content)
	 * @param string|null $meetingPath Path returned by MeetingFolderService (null = Files down)
	 *
	 * @return MeetingPackageService
	 */
	private function makeService(
		array $meetings,
		array $items,
		array $itemFiles,
		\ArrayObject $written,
		?string $meetingPath = 'Decidesk/2026-06-12 Board meeting',
	): MeetingPackageService {
		$logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($meetings): ?ObjectEntity {
				if (isset($meetings[(string)$id]) === false) {
					return null;
				}

				$row = $meetings[(string)$id];
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);
		$objectService->method('findAll')->willReturn($items);

		$fileService = new class($written, $itemFiles) {

			/**
			 * @param \ArrayObject $written Written-files sink
			 * @param array<string, array<int, object>> $itemFiles Map of itemId => file nodes
			 */
			public function __construct(
				private \ArrayObject $written,
				private array $itemFiles,
			) {
			}

			/**
			 * Create (or return) a fake folder node recording writes.
			 *
			 * @param string $folderPath Folder path
			 *
			 * @return object Folder-shaped fake with newFile()
			 */
			public function createFolder(string $folderPath): object {
				return new class($this->written, $folderPath) {
					/**
					 * @param \ArrayObject $written Written-files sink
					 * @param string $path Folder path
					 */
					public function __construct(
						private \ArrayObject $written,
						private string $path,
					) {
					}

					/**
					 * Record a new file write.
					 *
					 * @param string $name File name
					 * @param string $content File content
					 *
					 * @return object The fake folder
					 */
					public function newFile(string $name, string $content): object {
						$this->written[$this->path . '/' . $name] = $content;
						return $this;
					}
				};
			}

			/**
			 * Return the file nodes attached to an object.
			 *
			 * @param string $object Object id
			 *
			 * @return array<int, object> File nodes
			 */
			public function getFiles(string $object): array {
				return ($this->itemFiles[$object] ?? []);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $service) use ($objectService, $fileService): object {
				if ($service === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}

				return $objectService;
			}
		);

		$folderService = $this->createMock(MeetingFolderService::class);
		$folderService->method('ensureMeetingFolders')->willReturn($meetingPath);

		return new MeetingPackageService($container, $logger, $folderService);
	}//end makeService()

	/**
	 * The TOC lists agenda items by number and title in orderNumber order,
	 * with their document names.
	 *
	 * @return void
	 */
	public function testBuildTableOfContentsOrdersByItemNumber(): void {
		$service = $this->makeService([], [], [], new \ArrayObject());

		$toc = $service->buildTableOfContents(
			['title' => 'ALV 2026', 'scheduledDate' => '2026-06-01T13:00:00Z'],
			[
				['title' => 'Opening', 'itemType' => 'informational', 'estimatedDuration' => 5],
				['title' => 'Jaarrekening 2025', 'itemType' => 'decision', 'files' => [['name' => 'jaarrekening.pdf']]],
				['title' => 'Rondvraag'],
			]
		);

		$this->assertStringContainsString('# ALV 2026 — 2026-06-01', $toc);
		$this->assertStringContainsString('01. Opening', $toc);
		$this->assertStringContainsString('02. Jaarrekening 2025', $toc);
		$this->assertStringContainsString('03. Rondvraag', $toc);
		$this->assertStringContainsString('- jaarrekening.pdf', $toc);

		// Order: Opening before Jaarrekening before Rondvraag.
		$this->assertLessThan(strpos($toc, '02. Jaarrekening'), strpos($toc, '01. Opening'));
		$this->assertLessThan(strpos($toc, '03. Rondvraag'), strpos($toc, '02. Jaarrekening'));

	}//end testBuildTableOfContentsOrdersByItemNumber()

	/**
	 * The TOC degrades gracefully without agenda items.
	 *
	 * @return void
	 */
	public function testBuildTableOfContentsEmptyAgenda(): void {
		$service = $this->makeService([], [], [], new \ArrayObject());

		$toc = $service->buildTableOfContents(['title' => 'Board meeting'], []);

		$this->assertStringContainsString('_No agenda items._', $toc);

	}//end testBuildTableOfContentsEmptyAgenda()

	/**
	 * Unknown meetings (OR RBAC null → not found) are rejected.
	 *
	 * @return void
	 */
	public function testAssembleRejectsUnknownMeeting(): void {
		$written = new \ArrayObject();
		$service = $this->makeService([], [], [], $written);

		$result = $service->assemble('unknown', 'alice');

		$this->assertFalse($result['success']);
		$this->assertSame('Meeting not found.', $result['message']);
		$this->assertCount(0, $written);

	}//end testAssembleRejectsUnknownMeeting()

	/**
	 * assemble copies each agenda item's documents into a numbered folder
	 * and writes the TOC, sorting items by orderNumber.
	 *
	 * @return void
	 */
	public function testAssembleBuildsStructuredPackage(): void {
		$fileA = new class {

			/**
			 * @return string File name
			 */
			public function getName(): string {
				return 'begroting.pdf';
			}

			/**
			 * @return string File content
			 */
			public function getContent(): string {
				return '%PDF begroting';
			}
		};

		$written = new \ArrayObject();
		$service = $this->makeService(
			meetings: [
				'm-1' => [
					'id' => 'm-1',
					'title' => 'Board meeting',
					'scheduledDate' => '2026-06-12T14:00:00Z',
				],
			],
			items: [
				['id' => 'ai-2', 'title' => 'Begroting', 'orderNumber' => 2, 'meeting' => 'm-1'],
				['id' => 'ai-1', 'title' => 'Opening', 'orderNumber' => 1, 'meeting' => 'm-1'],
			],
			itemFiles: ['ai-2' => [$fileA]],
			written: $written
		);

		$result = $service->assemble('m-1', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('Decidesk/2026-06-12 Board meeting/Meeting package', $result['path']);
		$this->assertSame(2, $result['items']);
		$this->assertSame(1, $result['files']);
		$this->assertSame([], $result['skipped']);

		// TOC written at the package root; document copied into the
		// orderNumber-sorted "02 - Begroting" folder.
		$tocPath = 'Decidesk/2026-06-12 Board meeting/Meeting package/00 - Table of contents.md';
		$this->assertTrue($written->offsetExists($tocPath));
		$this->assertStringContainsString('01. Opening', $written[$tocPath]);
		$this->assertStringContainsString('02. Begroting', $written[$tocPath]);

		$docPath = 'Decidesk/2026-06-12 Board meeting/Meeting package/02 - Begroting/begroting.pdf';
		$this->assertTrue($written->offsetExists($docPath));
		$this->assertSame('%PDF begroting', $written[$docPath]);

	}//end testAssembleBuildsStructuredPackage()

	/**
	 * assemble fails cleanly when the meeting folder cannot be created.
	 *
	 * @return void
	 */
	public function testAssembleFailsWhenFilesUnavailable(): void {
		$written = new \ArrayObject();
		$service = $this->makeService(
			meetings: ['m-1' => ['id' => 'm-1', 'title' => 'Board meeting']],
			items: [],
			itemFiles: [],
			written: $written,
			meetingPath: null
		);

		$result = $service->assemble('m-1', 'alice');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('meeting folder', $result['message']);

	}//end testAssembleFailsWhenFilesUnavailable()

	/**
	 * Agenda items not linked to the meeting are filtered out defensively.
	 *
	 * @return void
	 */
	public function testAssembleFiltersForeignAgendaItems(): void {
		$written = new \ArrayObject();
		$service = $this->makeService(
			meetings: ['m-1' => ['id' => 'm-1', 'title' => 'Board meeting']],
			items: [
				['id' => 'ai-1', 'title' => 'Ours', 'orderNumber' => 1, 'meeting' => 'm-1'],
				['id' => 'ai-2', 'title' => 'Foreign', 'orderNumber' => 2, 'meeting' => 'other-meeting'],
			],
			itemFiles: [],
			written: $written
		);

		$result = $service->assemble('m-1', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['items']);

		$tocPath = 'Decidesk/2026-06-12 Board meeting/Meeting package/00 - Table of contents.md';
		$this->assertStringContainsString('01. Ours', $written[$tocPath]);
		$this->assertStringNotContainsString('Foreign', $written[$tocPath]);

	}//end testAssembleFiltersForeignAgendaItems()

}//end class
