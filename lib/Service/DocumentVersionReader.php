<?php

/**
 * Decidiq Document Version Reader
 *
 * The version of a Nextcloud file as the user can read it now. The version
 * identifier is the file's modification time, which is what Nextcloud's file
 * versions name each stored version by; the label counts the stored versions
 * before it, so the first upload is v1 and each new upload adds one.
 *
 * Read as the user: a file the user cannot read is not found, and nor are its
 * annotations. A paper OpenRegister stores for a meeting or an agenda item is
 * not in the user's own files: it is read only when the caller may read that
 * object and the object records the file as one of its papers, and the
 * annotator then shows it through decidiq's paper view (watermark and view
 * log), never through the user's files.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Throwable;

/**
 * Reads a file's current version as the user sees it.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
 */
class DocumentVersionReader {
	/**
	 * Constructor.
	 *
	 * @param IRootFolder            $rootFolder    Nextcloud's file tree
	 * @param ObjectServiceInterface $objectService Reads the paper's object under the caller's rights
	 * @param PaperLocator           $locator       Tells whether the object records the file as a paper
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ObjectServiceInterface $objectService,
		private readonly PaperLocator $locator,
	) {
	}//end __construct()

	/**
	 * The current version of a file the user can read.
	 *
	 * A file in the user's own files answers with its path there. A paper
	 * OpenRegister stores answers with an empty path and the object it is
	 * read through (`paper`), so the annotator opens it by the paper view.
	 *
	 * @param string                                  $userId The user
	 * @param int                                     $fileId The Nextcloud file id
	 * @param array{schema?: string, objectId?: string} $paper  The meeting or agenda item the paper was opened from
	 *
	 * @return array{fileVersion: string, versionLabel: string, name: string, path: string, paper: array{schema: string, objectId: string}|null}
	 *
	 * @throws MissingObjectException When the user cannot read the file.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
	 */
	public function current(string $userId, int $fileId, array $paper = []): array {
		if ($userId === '' || $fileId < 1) {
			throw new MissingObjectException(message: 'Document not found.');
		}

		$via = null;
		[$file, $path] = $this->readableFile(userId: $userId, fileId: $fileId);
		if ($file === null) {
			[$file, $via] = $this->paperFile(fileId: $fileId, paper: $paper);
		}

		return [
			'fileVersion'  => (string)$file->getMTime(),
			'versionLabel' => 'v' . ($this->storedVersions(file: $file) + 1),
			'name'         => $file->getName(),
			'path'         => $path,
			'paper'        => $via,
		];
	}//end current()

	/**
	 * A paper OpenRegister stores for an object the caller may read.
	 *
	 * The object is read under the caller's own rights, and the file must be
	 * one of its recorded papers: knowing a file id opens nothing.
	 *
	 * @param int                                       $fileId The file id
	 * @param array{schema?: string, objectId?: string} $paper  The meeting or agenda item
	 *
	 * @return array{0: File, 1: array{schema: string, objectId: string}}
	 *
	 * @throws MissingObjectException When the object or the paper cannot be read.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
	 */
	private function paperFile(int $fileId, array $paper): array {
		$schema   = (string)($paper['schema'] ?? '');
		$objectId = trim((string)($paper['objectId'] ?? ''));
		if (in_array($schema, ['agenda-item', 'meeting'], true) === false || $objectId === '') {
			throw new MissingObjectException(message: 'Document not found.');
		}

		try {
			$object = $this->objectService->find(id: $objectId, options: new ObjectFindOptions(register: 'decidiq', schema: $schema));
		} catch (Throwable) {
			$object = null;
		}

		if ($object === null) {
			throw new MissingObjectException(message: 'Document not found.');
		}

		$located = $this->locator->locate(schema: $schema, objectId: $objectId, data: $object->getObject(), fileId: $fileId);
		if ($located === null) {
			throw new MissingObjectException(message: 'Document not found.');
		}

		return [$located['file'], ['schema' => $schema, 'objectId' => $objectId]];
	}//end paperFile()

	/**
	 * The file, when it is in the user's own files and readable, and its
	 * path there; nulls when it is not there.
	 *
	 * @param string $userId The user
	 * @param int    $fileId The file id
	 *
	 * @return array{0: File|null, 1: string}
	 *
	 * @throws MissingObjectException When it is there but a folder or unreadable.
	 */
	private function readableFile(string $userId, int $fileId): array {
		$node = null;
		$path = null;
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$node = $userFolder->getFirstNodeById($fileId);
			if ($node !== null) {
				$path = $userFolder->getRelativePath($node->getPath());
			}
		} catch (NotFoundException) {
			$node = null;
		}

		if ($node === null) {
			return [null, ''];
		}

		if (($node instanceof File) === false || $node->isReadable() === false || is_string($path) === false) {
			throw new MissingObjectException(message: 'Document not found.');
		}

		return [$node, $path];
	}//end readableFile()

	/**
	 * How many earlier versions Nextcloud keeps of the file.
	 *
	 * Nextcloud stores them beside the owner's files as `<name>.v<mtime>`
	 * under `files_versions`. No such folder means no earlier version.
	 *
	 * @param File $file The file
	 *
	 * @return int
	 */
	private function storedVersions(File $file): int {
		$owner = $file->getOwner();
		$internal = $file->getInternalPath();
		if ($owner === null || str_starts_with($internal, 'files/') === false) {
			return 0;
		}

		$relative = substr($internal, strlen('files/'));
		$directory = dirname($relative);
		$path = '/' . $owner->getUID() . '/files_versions';
		if ($directory !== '.' && $directory !== '') {
			$path .= '/' . $directory;
		}

		try {
			$folder = $this->rootFolder->get($path);
		} catch (NotFoundException) {
			return 0;
		}

		if (($folder instanceof Folder) === false) {
			return 0;
		}

		$pattern = '/^' . preg_quote(basename($relative), '/') . '\.v\d+$/';
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if (preg_match($pattern, $node->getName()) === 1) {
				$count++;
			}
		}

		return $count;
	}//end storedVersions()
}//end class
