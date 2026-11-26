<?php

/**
 * Unit tests for DocumentVersionReader: a file in the user's own files is
 * read there; a paper OpenRegister stores only through the meeting or agenda
 * item it belongs to, which the caller must be able to read.
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
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\AnnotationService;
use OCA\Decidiq\Service\DocumentVersionReader;
use OCA\Decidiq\Service\PaperLocator;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Knowing a file id opens nothing: a stored paper needs a readable object
 * that holds it. The paper lives in OpenRegister's own account, found
 * through the mounts Nextcloud knows for its id.
 *
 * @covers \OCA\Decidiq\Service\DocumentVersionReader
 * @covers \OCA\Decidiq\Service\PaperLocator
 * @covers \OCA\Decidiq\Service\AnnotationService::paperOf
 *
 * @uses \OCA\Decidiq\Exception\MissingObjectException
 */
class DocumentVersionReaderTest extends TestCase {

	private const ITEM = '11111111-2222-4333-8444-555555555555';

	private const OTHER = '22222222-2222-4333-8444-555555555555';

	private const PAPER = 77;

	/**
	 * The reader over a tree where file 77 is an OpenRegister paper in the
	 * item's folder, not in the user's files.
	 *
	 * @param array<string, mixed>|null $item   What the caller's read of the item answers; null when refused
	 * @param string                    $folder The name of the folder that holds the paper
	 * @param array<int, string>|null   $reads  Collects the object reads (schema)
	 *
	 * @return DocumentVersionReader
	 */
	private function reader(?array $item, string $folder=self::ITEM, ?array &$reads=null): DocumentVersionReader {
		$parent = $this->createMock(originalClassName: Folder::class);
		$parent->method('getName')->willReturn($folder);

		$paper = $this->createMock(originalClassName: File::class);
		$paper->method('getMTime')->willReturn(1764000000);
		$paper->method('getName')->willReturn('Raadsvoorstel.pdf');
		$paper->method('getParent')->willReturn($parent);
		$paper->method('getOwner')->willReturn(null);
		$paper->method('getInternalPath')->willReturn('appdata/openregister/Raadsvoorstel.pdf');

		// The caller's own files do not hold the paper; OpenRegister's account
		// does, and its storage is not set up until its folder is read.
		$callerFolder = $this->createMock(originalClassName: Folder::class);
		$callerFolder->method('getFirstNodeById')->willReturn(null);
		$storeFolder = $this->createMock(originalClassName: Folder::class);
		$storeFolder->method('getFirstNodeById')->willReturnMap([[self::PAPER, $paper]]);

		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['openregister', $storeFolder], ['carol', $callerFolder], ['dave', $callerFolder]]);
		$root->method('getFirstNodeById')->willReturn(null);
		$root->method('get')->willThrowException(new NotFoundException('none'));

		$account = $this->createMock(originalClassName: IUser::class);
		$account->method('getUID')->willReturn('openregister');
		$mount = $this->createMock(originalClassName: ICachedMountInfo::class);
		$mount->method('getUser')->willReturn($account);
		$mounts = $this->createMock(originalClassName: IUserMountCache::class);
		$mounts->method('getMountsForFileId')->willReturnMap([[self::PAPER, null, [$mount]]]);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($item, &$reads): ?ObjectEntityInterface {
				$reads[] = ($args[1] ?? null)?->schema;
				// The caller's own read: rbac and multitenancy stay on.
				self::assertTrue(condition: ($args[2] ?? true) === true && ($args[3] ?? true) === true);
				if ($item === null) {
					throw new \RuntimeException('not allowed');
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($item);
				return $entity;
			}
		);

		return new DocumentVersionReader(
			rootFolder: $root,
			objectService: $objects,
			locator: new PaperLocator(objectService: $objects, rootFolder: $root, logger: new NullLogger(), mountCache: $mounts)
		);
	}//end reader()

	/**
	 * A paper in the item's own folder, opened from an item the caller can
	 * read, answers its version and the object it is read through.
	 *
	 * @return void
	 */
	public function testAStoredPaperIsReadThroughItsAgendaItem(): void {
		$reads   = [];
		$current = $this->reader(item: ['title' => 'Omgevingsvisie'], reads: $reads)
			->current(userId: 'carol', fileId: self::PAPER, paper: ['schema' => 'agenda-item', 'objectId' => self::ITEM]);

		self::assertSame(expected: '1764000000', actual: $current['fileVersion']);
		self::assertSame(expected: 'v1', actual: $current['versionLabel']);
		self::assertSame(expected: 'Raadsvoorstel.pdf', actual: $current['name']);
		self::assertSame(expected: '', actual: $current['path']);
		self::assertSame(expected: ['schema' => 'agenda-item', 'objectId' => self::ITEM], actual: $current['paper']);
		self::assertSame(expected: ['agenda-item'], actual: $reads);
	}//end testAStoredPaperIsReadThroughItsAgendaItem()

	/**
	 * A recorded rendition counts as the item's paper wherever it is kept.
	 *
	 * @return void
	 */
	public function testARecordedRenditionIsReadThroughItsAgendaItem(): void {
		$current = $this->reader(item: ['paperRenditions' => [['sourceFileId' => 76, 'pdfFileId' => self::PAPER]]], folder: 'elsewhere')
			->current(userId: 'carol', fileId: self::PAPER, paper: ['schema' => 'agenda-item', 'objectId' => self::ITEM]);

		self::assertSame(expected: ['schema' => 'agenda-item', 'objectId' => self::ITEM], actual: $current['paper']);
	}//end testARecordedRenditionIsReadThroughItsAgendaItem()

	/**
	 * A caller who may not read the item does not read its paper.
	 *
	 * @return void
	 */
	public function testAnUnreadableItemOpensNoPaper(): void {
		$this->expectException(exception: MissingObjectException::class);
		$this->reader(item: null)->current(userId: 'dave', fileId: self::PAPER, paper: ['schema' => 'agenda-item', 'objectId' => self::ITEM]);
	}//end testAnUnreadableItemOpensNoPaper()

	/**
	 * Naming another readable item does not open a paper that item does not hold.
	 *
	 * @return void
	 */
	public function testAnItemThatDoesNotHoldThePaperOpensNothing(): void {
		$this->expectException(exception: MissingObjectException::class);
		$this->reader(item: ['title' => 'Ander punt'])
			->current(userId: 'carol', fileId: self::PAPER, paper: ['schema' => 'agenda-item', 'objectId' => self::OTHER]);
	}//end testAnItemThatDoesNotHoldThePaperOpensNothing()

	/**
	 * Without a meeting or agenda item, or with another kind of object, a
	 * file outside the user's files is not found and no object is read.
	 *
	 * @return void
	 */
	public function testNoObjectOrAnotherSchemaOpensNothing(): void {
		foreach ([[], ['schema' => 'decision', 'objectId' => self::ITEM], ['schema' => 'meeting', 'objectId' => ' ']] as $paper) {
			$reads = [];
			try {
				$this->reader(item: ['title' => 'x'], reads: $reads)->current(userId: 'carol', fileId: self::PAPER, paper: $paper);
				self::fail(message: 'A paper opened without a readable object that holds it.');
			} catch (MissingObjectException) {
				self::assertSame(expected: [], actual: $reads);
			}
		}
	}//end testNoObjectOrAnotherSchemaOpensNothing()

	/**
	 * The request's context names the agenda item first, else the meeting.
	 *
	 * @return void
	 */
	public function testThePaperIsReadThroughTheAgendaItemBeforeTheMeeting(): void {
		self::assertSame(expected: ['schema' => 'agenda-item', 'objectId' => 'i'], actual: AnnotationService::paperOf(context: ['agendaItem' => ' i ', 'meeting' => 'm']));
		self::assertSame(expected: ['schema' => 'meeting', 'objectId' => 'm'], actual: AnnotationService::paperOf(context: ['agendaItem' => '', 'meeting' => 'm']));
		self::assertSame(expected: [], actual: AnnotationService::paperOf(context: []));
	}//end testThePaperIsReadThroughTheAgendaItemBeforeTheMeeting()
}//end class
