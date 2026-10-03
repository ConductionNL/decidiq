<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\Chunk.
 *
 * Like the real entity (ConductionNL/openregister@origin/development
 * lib/Db/Chunk.php) it extends OCP's Entity, so getTextContent() and
 * getChunkIndex() are the same magic accessors production answers. Only the
 * two fields decidiq reads are declared.
 *
 * @package OCA\Decidiq\Tests\Stubs
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Minimal stand-in for OpenRegister's Chunk entity.
 *
 * @method string|null getTextContent()
 * @method void setTextContent(string $textContent)
 * @method int getChunkIndex()
 * @method void setChunkIndex(int $chunkIndex)
 */
class Chunk extends Entity {
	/**
	 * The chunk's text.
	 *
	 * @var string|null
	 */
	protected ?string $textContent = null;

	/**
	 * The chunk's position in its source.
	 *
	 * @var int
	 */
	protected int $chunkIndex = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'textContent', type: 'string');
		$this->addType(fieldName: 'chunkIndex', type: 'integer');
	}//end __construct()
}//end class
