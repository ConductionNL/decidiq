<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\ChunkMapper.
 *
 * Same signature parity contract as tests/Stubs/Service/FileService.php: its
 * one method is copied verbatim from
 * ConductionNL/openregister@origin/development lib/Db/ChunkMapper.php
 * (findBySource() line 93, read 3 Oct 2026). Only the method decidiq calls
 * (lib/Service/PaperText.php) is declared.
 *
 * @package OCA\Decidiq\Tests\Stubs
 */

namespace OCA\OpenRegister\Db;

/**
 * Minimal stand-in for OpenRegister's ChunkMapper.
 */
class ChunkMapper {
	/**
	 * The chunks of one source, in chunk order.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param int    $sourceId   Source identifier.
	 *
	 * @return Chunk[] The chunks.
	 */
	public function findBySource(string $sourceType, int $sourceId): array {
		throw new \RuntimeException('ChunkMapper stub: findBySource() must be mocked in tests.');
	}//end findBySource()
}//end class
