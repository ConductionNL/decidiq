<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
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
 * Stub implementation of ObjectUpdatedEvent for unit testing.
 */
class ObjectUpdatedEvent extends Event
{
    private ?ObjectEntity $newObject;
    private ?ObjectEntity $oldObject;

    public function __construct(?ObjectEntity $newObject = null, ?ObjectEntity $oldObject = null)
    {
        parent::__construct();
        $this->newObject = $newObject;
        $this->oldObject = $oldObject;
    }

    public function getObject(): ?ObjectEntity
    {
        return $this->newObject;
    }

    public function getNewObject(): ?ObjectEntity
    {
        return $this->newObject;
    }

    public function getOldObject(): ?ObjectEntity
    {
        return $this->oldObject;
    }
}
