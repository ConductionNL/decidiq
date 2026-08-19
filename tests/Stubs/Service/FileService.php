<?php

/**
 * Test stub for OpenRegister's FileService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\Files\Node;

/**
 * Abstract stand-in for OpenRegister's FileService in standalone test runs.
 *
 * ADR-084 turned several container lookups into typed `FileService` parameters,
 * so `createMock(FileService::class)` now has to resolve to *something* — before
 * this stub existed PHPUnit raised `UnknownTypeException`, and the tests that
 * happened not to reach the mock were the only reason the suite looked healthy.
 *
 * It is `abstract` for the same reason `tests/Stubs/Service/ObjectService.php`
 * is: nothing may ever instantiate it and mistake it for the real service. Only
 * the surface decidesk actually calls is declared, and each signature mirrors
 * the real class so a drift in OpenRegister shows up as a PHP error here rather
 * than as a silently-passing double (#399).
 */
abstract class FileService {

	/**
	 * Create (or return) a folder at the given path.
	 *
	 * @param string $folderPath The folder path.
	 *
	 * @return Node The folder node.
	 */
	abstract public function createFolder(string $folderPath): Node;

	/**
	 * List the files attached to an object.
	 *
	 * @param object|string $object           The object or its uuid.
	 * @param bool|null     $sharedFilesOnly  Restrict to shared files.
	 *
	 * @return array<int,mixed> The file nodes.
	 */
	abstract public function getFiles(object|string $object, ?bool $sharedFilesOnly=false): array;
}//end class
