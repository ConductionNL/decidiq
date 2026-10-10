<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectDeletedEvent.
 *
 * Defines the class in the correct namespace so unit tests can exercise
 * listeners without the OpenRegister app installed. Resolved through the
 * PSR-4 stub root in tests/bootstrap-unit.php; NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub implementation of ObjectDeletedEvent for unit testing.
 */
class ObjectDeletedEvent extends Event {
	private ?ObjectEntity $object;

	public function __construct(?ObjectEntity $object = null) {
		parent::__construct();
		$this->object = $object;
	}

	public function getObject(): ?ObjectEntity {
		return $this->object;
	}
}
