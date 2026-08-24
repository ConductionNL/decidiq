<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 *
 * Defines the class in the correct namespace so that unit tests can exercise
 * BoardMeetingCalDavBridge without requiring the OpenRegister app to be
 * installed as a Composer dependency.
 *
 * This file is loaded by tests/bootstrap-unit.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub implementation of ObjectCreatedEvent for unit testing.
 */
class ObjectCreatedEvent extends Event {
	private ?ObjectEntity $object;

	public function __construct(?ObjectEntity $object = null) {
		parent::__construct();
		$this->object = $object;
	}

	public function getObject(): ?ObjectEntity {
		return $this->object;
	}
}
