<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\FileService.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * This stub stands in for the real service only when the OpenRegister app is
 * not installed. Every signature below is copied verbatim from production; a
 * looser one here is not a convenience but a hole — PHPUnit generates its mock
 * from whichever class is on the autoloader, so a stub that returns `mixed`
 * lets `->willReturn([...])` stand in for a value production can never emit,
 * and the whole suite goes green on assertions that cannot hold in the app.
 *
 * Matched against ConductionNL/openregister@origin/development,
 * lib/Service/FileService.php (commit a8cc77387):
 *
 *   createFolder() line 1290
 *   getFiles()     line 1562
 *
 * Only the two methods decidiq actually calls are declared
 * (`lib/Service/VotingRoundCloser.php`, plus container-resolved use in
 * `lib/Service/TranscriptionSourceResolver.php` and
 * `lib/BackgroundJob/TranscriptRetentionJob.php`). A stub method that
 * production does NOT have is the same defect in the other direction: a mock
 * configured against it passes here and raises an error in the real app.
 *
 * Added 2026-08-19: its absence made 87 unit tests ERROR locally
 * ("Class ... FileService does not exist") while CI stayed green, because app
 * CI clones the real OpenRegister at ref: development. A local suite that
 * cannot run is not a suite that passes.
 *
 * @package OCA\Decidiq\Tests\Stubs
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Node;

/**
 * Minimal stand-in for OpenRegister's FileService.
 */
class FileService {
	/**
	 * Create a folder at the given path.
	 *
	 * @param string $folderPath Path of the folder to create.
	 *
	 * @return Node The created folder node.
	 */
	public function createFolder(string $folderPath): Node {
		throw new \RuntimeException('FileService stub: createFolder() must be mocked in tests.');
	}//end createFolder()

	/**
	 * Get the files attached to an object.
	 *
	 * @param ObjectEntity|string $object The object or its identifier.
	 * @param boolean|null $sharedFilesOnly Whether to return only shared files.
	 *
	 * @return array The files attached to the object.
	 */
	public function getFiles(ObjectEntity|string $object, ?bool $sharedFilesOnly = false): array {
		throw new \RuntimeException('FileService stub: getFiles() must be mocked in tests.');
	}//end getFiles()

	/**
	 * Add a file to an object's folder. Same signature as OpenRegister's
	 * FileService::addFile() on development.
	 *
	 * @param ObjectEntity|string $objectEntity The object to add the file to.
	 * @param string $fileName The name of the file to create.
	 * @param mixed $content The file content.
	 * @param boolean $share Whether to create a share link.
	 * @param array<int, string> $tags Tags to attach.
	 * @param mixed $_schema The schema of the object.
	 * @param mixed $_register The register of the object.
	 * @param integer|string|null $registerId The register id.
	 *
	 * @return File The created file.
	 */
	public function addFile(
		ObjectEntity|string $objectEntity,
		string $fileName,
		mixed $content,
		bool $share = false,
		array $tags = [],
		mixed $_schema = null,
		mixed $_register = null,
		int|string|null $registerId = null,
	): File {
		throw new \RuntimeException('FileService stub: addFile() must be mocked in tests.');
	}//end addFile()
	/**
	 * Format a file node for the API. Same signature as OpenRegister's
	 * FileService::formatFile() on development (line 1083 at 4abd8343).
	 *
	 * @param Node $file The file node.
	 *
	 * @return array The formatted file (id, title, downloadUrl, type, labels, ...).
	 */
	public function formatFile(Node $file): array {
		throw new \RuntimeException('FileService stub: formatFile() must be mocked in tests.');
	}//end formatFile()

	/**
	 * Make a file of an object public. Same signature as OpenRegister's
	 * FileService::publishFile() on development (line 1785 at 4abd8343).
	 *
	 * @param ObjectEntity|string $object The object or its identifier.
	 * @param string|int $file The file id or path.
	 *
	 * @return File The published file.
	 */
	public function publishFile(ObjectEntity|string $object, string|int $file): File {
		throw new \RuntimeException('FileService stub: publishFile() must be mocked in tests.');
	}//end publishFile()

	/**
	 * Take a file of an object offline. Same signature as OpenRegister's
	 * FileService::unpublishFile() on development (line 1810 at 4abd8343).
	 *
	 * @param ObjectEntity|string $object The object or its identifier.
	 * @param string|int $filePath The file id or path.
	 *
	 * @return File The unpublished file.
	 */
	public function unpublishFile(ObjectEntity|string $object, string|int $filePath): File {
		throw new \RuntimeException('FileService stub: unpublishFile() must be mocked in tests.');
	}//end unpublishFile()
}//end class
