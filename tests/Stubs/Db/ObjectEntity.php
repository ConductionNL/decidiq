<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Provides the method signatures that decidesk tests use to mock the entity
 * returned by ObjectService::find(). Loaded by bootstrap-unit.php when the
 * real OpenRegister app is not installed.
 *
 * Methods are CONCRETE (not abstract) so PHPUnit's createMock() can build a
 * usable double without forcing every test to provide every method override.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for ObjectEntity with concrete defaults; mocks override per-test.
 */
class ObjectEntity implements \JsonSerializable
{


    /**
     * Return the raw object data as an array.
     *
     * @return array<string,mixed>
     */
    public function getObject(): array
    {
        return [];

    }//end getObject()


    /**
     * Return the object UUID.
     *
     * @return string
     */
    public function getUuid(): string
    {
        return '';

    }//end getUuid()


    /**
     * Return a JSON-serializable representation of the entity.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [];

    }//end jsonSerialize()


}//end class
