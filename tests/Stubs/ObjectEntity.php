<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Defines the class in the correct namespace so that unit tests can mock
 * ObjectEntity without requiring the OpenRegister app to be installed.
 *
 * This file is loaded by tests/bootstrap.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub implementation of ObjectEntity for unit testing.
 *
 * Provides only the method signatures used by Decidesk unit tests.
 * PHPUnit's createMock() will override all methods.
 */
class ObjectEntity implements \JsonSerializable
{

    /**
     * Return the raw object data array.
     *
     * @return array<string,mixed>
     */
    public function getObject(): array
    {
        return [];

    }//end getObject()


    /**
     * Serialize for JSON encoding.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [];

    }//end jsonSerialize()


    /**
     * Return the object UUID.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return null;

    }//end getUuid()


}//end class
