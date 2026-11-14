<?php

/**
 * Unit tests for MeetingPackageService.
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
 * @spec openspec/specs/agenda-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Exception\PaperStampRefusedException;
use OCA\Decidiq\Service\MeetingPackageService;
use OCA\Decidiq\Service\PaperStamper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserManager;
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
	 * @param PaperStamper|null $stamper The stamper; by default no paper needs a stamp
	 *
	 * @return MeetingPackageService
	 */
	private function makeService(
		array $meetings,
		array $items,
		array $itemFiles,
		\ArrayObject $written,
		?string $meetingPath = 'Decidiq/2026-06-12 Board meeting',
		?PaperStamper $stamper = null,
	): MeetingPackageService {
		if ($stamper === null) {
			$stamper = $this->createMock(PaperStamper::class);
			$stamper->method('mustStamp')->willReturn(false);
			$stamper->expects($this->never())->method('stamped');
		}

		$jan = $this->createMock(IUser::class);
		$jan->method('getDisplayName')->willReturn('Jan de Vries');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'jan' ? $jan : null);

		$logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectServiceInterface::class);
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

		return new MeetingPackageService($container, $logger, $folderService, $stamper, $users);
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
		$this->assertSame('Decidiq/2026-06-12 Board meeting/Meeting package', $result['path']);
		$this->assertSame(2, $result['items']);
		$this->assertSame(1, $result['files']);
		$this->assertSame([], $result['skipped']);

		// TOC written at the package root; document copied into the
		// orderNumber-sorted "02 - Begroting" folder.
		$tocPath = 'Decidiq/2026-06-12 Board meeting/Meeting package/00 - Table of contents.md';
		$this->assertTrue($written->offsetExists($tocPath));
		$this->assertStringContainsString('01. Opening', $written[$tocPath]);
		$this->assertStringContainsString('02. Begroting', $written[$tocPath]);

		$docPath = 'Decidiq/2026-06-12 Board meeting/Meeting package/02 - Begroting/begroting.pdf';
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

		$tocPath = 'Decidiq/2026-06-12 Board meeting/Meeting package/00 - Table of contents.md';
		$this->assertStringContainsString('01. Ours', $written[$tocPath]);
		$this->assertStringNotContainsString('Foreign', $written[$tocPath]);

	}//end testAssembleFiltersForeignAgendaItems()

	/**
	 * A non-public item is in the pack by its place only: listed as
	 * "Besloten punt", with no title, no folder of papers and no file copied.
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
	 *
	 * @return void
	 */
	public function testAssembleListsANonPublicItemAsBeslotenPuntWithoutPapers(): void {
		$paper = new class {

			/**
			 * @return string File name
			 */
			public function getName(): string {
				return 'koopprijs.pdf';
			}

			/**
			 * @return string File content
			 */
			public function getContent(): string {
				return '%PDF koopprijs';
			}
		};

		$written = new \ArrayObject();
		$service = $this->makeService(
			meetings: ['m-1' => ['id' => 'm-1', 'title' => 'Board meeting']],
			items: [
				['id' => 'ai-1', 'title' => 'Opening', 'orderNumber' => 1, 'meeting' => 'm-1'],
				['id' => 'ai-7', 'title' => 'Grondaankoop Noordkade', 'orderNumber' => 7, 'meeting' => 'm-1', 'public' => false],
			],
			itemFiles: ['ai-7' => [$paper]],
			written: $written
		);

		$result = $service->assemble('m-1', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame(2, $result['items']);
		$this->assertSame(0, $result['files']);

		$tocPath = 'Decidiq/2026-06-12 Board meeting/Meeting package/00 - Table of contents.md';
		$this->assertStringContainsString('02. Besloten punt', $written[$tocPath]);
		$everything = implode("\n", array_keys($written->getArrayCopy())) . "\n" . implode("\n", $written->getArrayCopy());
		$this->assertStringNotContainsString('Noordkade', $everything);
		$this->assertStringNotContainsString('koopprijs', $everything);

	}//end testAssembleListsANonPublicItemAsBeslotenPuntWithoutPapers()

	/**
	 * A paper node with an id and a version, as Files hands them out.
	 *
	 * @return object
	 */
	private function paperNode(): object {
		return new class {

			/**
			 * @return string File name
			 */
			public function getName(): string {
				return 'grondtransactie.pdf';
			}

			/**
			 * @return string File content
			 */
			public function getContent(): string {
				return '%PDF grond';
			}

			/**
			 * @return int File id
			 */
			public function getId(): int {
				return 77;
			}

			/**
			 * @return string File version
			 */
			public function getEtag(): string {
				return 'e7';
			}
		};
	}//end paperNode()

	/**
	 * In a closed session every paper in Jan's package carries his name.
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it
	 *
	 * @return void
	 */
	public function testTheClosedSessionPackageIsStampedForWhoTakesIt(): void {
		$meeting = ['id' => 'm-1', 'title' => 'Besloten vergadering grondzaken', 'isPublic' => false];
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->expects($this->once())->method('mustStamp')->with($meeting, 'ai-1')->willReturn(true);
		$stamper->expects($this->once())->method('stamped')
			->with($meeting, ['fileId' => 77, 'etag' => 'e7', 'content' => '%PDF grond'], ['uid' => 'jan', 'displayName' => 'Jan de Vries'])
			->willReturn('%PDF grond, Jan de Vries');

		$written = new \ArrayObject();
		$service = $this->makeService(
			meetings: ['m-1' => $meeting],
			items: [['id' => 'ai-1', 'title' => 'Grondtransactie', 'orderNumber' => 1, 'meeting' => 'm-1']],
			itemFiles: ['ai-1' => [$this->paperNode()]],
			written: $written,
			stamper: $stamper
		);

		$result = $service->assemble('m-1', 'jan');

		$this->assertSame(1, $result['files']);
		$this->assertSame('%PDF grond, Jan de Vries', $written['Decidiq/2026-06-12 Board meeting/Meeting package/01 - Grondtransactie/grondtransactie.pdf']);
	}//end testTheClosedSessionPackageIsStampedForWhoTakesIt()

	/**
	 * A paper that must be stamped and cannot be is left out, never copied unstamped.
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it
	 *
	 * @return void
	 */
	public function testAPaperThatCannotBeStampedIsLeftOut(): void {
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->method('mustStamp')->willReturn(true);
		$stamper->method('stamped')->willThrowException(new PaperStampRefusedException(PaperStampRefusedException::NO_SERVICE, 503));

		$written = new \ArrayObject();
		$service = $this->makeService(
			meetings: ['m-1' => ['id' => 'm-1', 'title' => 'Besloten', 'isPublic' => false]],
			items: [['id' => 'ai-1', 'title' => 'Grondtransactie', 'orderNumber' => 1, 'meeting' => 'm-1']],
			itemFiles: ['ai-1' => [$this->paperNode()]],
			written: $written,
			stamper: $stamper
		);

		$result = $service->assemble('m-1', 'someone');

		$this->assertSame(0, $result['files']);
		$this->assertFalse($written->offsetExists('Decidiq/2026-06-12 Board meeting/Meeting package/01 - Grondtransactie/grondtransactie.pdf'));
		$this->assertSame(['01 - Grondtransactie/grondtransactie.pdf (' . PaperStampRefusedException::NO_SERVICE . ')'], $result['skipped']);
	}//end testAPaperThatCannotBeStampedIsLeftOut()
}//end class
