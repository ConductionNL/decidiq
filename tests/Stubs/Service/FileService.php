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
}//end class
