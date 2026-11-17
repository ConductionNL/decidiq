<?php

/**
 * Tests for MeetingFileTree: nested folders, file writes and a body's name.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingFileTree;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\MeetingFileTree
 */
class MeetingFileTreeTest extends TestCase {

	/**
	 * Every prefix of the path is created, outermost first, and the full path returned.
	 *
	 * @return void
	 */
	public function testCreatePathCreatesEveryFolderOutermostFirst(): void {
		$files = new class {
			/**
			 * The folders created, in order.
			 *
			 * @var array<int, string>
			 */
			public array $created = [];

			/**
			 * Record a created folder.
			 *
			 * @param string $path The path.
			 *
			 * @return void
			 */
			public function createFolder(string $path): void {
				$this->created[] = $path;
			}
		};

		self::assertSame('Raad/2026-11-16 Vergadering', MeetingFileTree::createPath(fileService: $files, segments: ['Raad', '2026-11-16 Vergadering']));
		self::assertSame(['Raad', 'Raad/2026-11-16 Vergadering'], $files->created);
	}//end testCreatePathCreatesEveryFolderOutermostFirst()

	/**
	 * An existing file gets the new content; no second file is made.
	 *
	 * @return void
	 */
	public function testPutFileReplacesAnExistingFile(): void {
		$file = $this->createMock(File::class);
		$file->expects(self::once())->method('putContent')->with('new');
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('notulen.pdf')->willReturn($file);
		$folder->expects(self::never())->method('newFile');

		MeetingFileTree::putFile(folderNode: $folder, name: 'notulen.pdf', content: 'new');
	}//end testPutFileReplacesAnExistingFile()

	/**
	 * A missing file is created with the content.
	 *
	 * @return void
	 */
	public function testPutFileCreatesAMissingFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->expects(self::once())->method('newFile')->with('notulen.pdf', 'new');

		MeetingFileTree::putFile(folderNode: $folder, name: 'notulen.pdf', content: 'new');
	}//end testPutFileCreatesAMissingFile()

	/**
	 * A uuid or a relation array resolves to the body's name, else its title.
	 *
	 * @return void
	 */
	public function testBodyNameReadsTheNameElseTheTitle(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (string $id, ObjectFindOptions $options): ObjectEntity {
				self::assertSame('decidiq', $options->register);
				self::assertSame('governance-body', $options->schema);
				$data = ['title' => 'Commissie'];
				if ($id === 'b-1') {
					$data = ['name' => 'Gemeenteraad', 'title' => 'ignored'];
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($data);
				return $entity;
			}
		);

		self::assertSame('Gemeenteraad', MeetingFileTree::bodyName(objectService: $objects, bodyId: 'b-1'));
		self::assertSame('Commissie', MeetingFileTree::bodyName(objectService: $objects, bodyId: ['id' => 'b-2']));
	}//end testBodyNameReadsTheNameElseTheTitle()

	/**
	 * No reference, an unknown body or a failing read all give ''.
	 *
	 * @return void
	 */
	public function testBodyNameIsEmptyWhenTheBodyCannotBeRead(): void {
		$none = $this->createMock(ObjectServiceInterface::class);
		$none->expects(self::never())->method('find');
		self::assertSame('', MeetingFileTree::bodyName(objectService: $none, bodyId: null));
		self::assertSame('', MeetingFileTree::bodyName(objectService: $none, bodyId: ''));
		self::assertSame('', MeetingFileTree::bodyName(objectService: $none, bodyId: ['name' => 'no id']));

		$missing = $this->createMock(ObjectServiceInterface::class);
		$missing->method('find')->willReturn(null);
		self::assertSame('', MeetingFileTree::bodyName(objectService: $missing, bodyId: 'gone'));

		$failing = $this->createMock(ObjectServiceInterface::class);
		$failing->method('find')->willThrowException(new RuntimeException('down'));
		self::assertSame('', MeetingFileTree::bodyName(objectService: $failing, bodyId: 'b-1'));
	}//end testBodyNameIsEmptyWhenTheBodyCannotBeRead()
}//end class
