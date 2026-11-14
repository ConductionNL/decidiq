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
 * annotations.
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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Reads a file's current version as the user sees it.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
 */
class DocumentVersionReader {
	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Nextcloud's file tree
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The current version of a file the user can read.
	 *
	 * @param string $userId The user
	 * @param int    $fileId The Nextcloud file id
	 *
	 * @return array{fileVersion: string, versionLabel: string, name: string, path: string}
	 *
	 * @throws MissingObjectException When the user cannot read the file.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
	 */
	public function current(string $userId, int $fileId): array {
		[$file, $path] = $this->readableFile(userId: $userId, fileId: $fileId);

		return [
			'fileVersion'  => (string)$file->getMTime(),
			'versionLabel' => 'v' . ($this->storedVersions(file: $file) + 1),
			'name'         => $file->getName(),
			'path'         => $path,
		];
	}//end current()

	/**
	 * The file, when the user can read it, and its path in the user's files.
	 *
	 * @param string $userId The user
	 * @param int    $fileId The file id
	 *
	 * @return array{0: File, 1: string}
	 *
	 * @throws MissingObjectException When it is missing, a folder or unreadable.
	 */
	private function readableFile(string $userId, int $fileId): array {
		if ($userId === '' || $fileId < 1) {
			throw new MissingObjectException(message: 'Document not found.');
		}

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
