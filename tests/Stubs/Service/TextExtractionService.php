<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\TextExtractionService.
 *
 * Same signature parity contract as tests/Stubs/Service/FileService.php: this
 * stands in for the real service only when the OpenRegister app is not
 * installed, and its one method is copied verbatim from
 * ConductionNL/openregister@origin/development lib/Service/TextExtractionService.php
 * (extractFile() line 206, read 3 Oct 2026). Only the method decidiq calls
 * (lib/Service/PaperText.php) is declared.
 *
 * @package OCA\Decidiq\Tests\Stubs
 */

namespace OCA\OpenRegister\Service;

/**
 * Minimal stand-in for OpenRegister's TextExtractionService.
 */
class TextExtractionService {
	/**
	 * Extract a Nextcloud file's text into chunks.
	 *
	 * @param int        $fileId         The Nextcloud file id.
	 * @param bool       $forceReExtract Extract again even when the chunks are current.
	 * @param array|null $entityTypes    Entity types to detect, null for none.
	 *
	 * @return void
	 */
	public function extractFile(int $fileId, bool $forceReExtract = false, ?array $entityTypes = null): void {
		throw new \RuntimeException('TextExtractionService stub: extractFile() must be mocked in tests.');
	}//end extractFile()
}//end class
