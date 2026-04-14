<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Provides the method signatures that MeetingServiceTest uses to mock the
 * entity returned by ObjectService::find(). Loaded by bootstrap-unit.php
 * when the real OpenRegister app is not installed.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for ObjectEntity with the methods used by Decidesk tests.
 */
abstract class ObjectEntity
{

    /**
     * Return the raw object data as an array.
     *
     * @return array<string,mixed>
     */
    abstract public function getObject(): array;

    /**
     * Return the object UUID.
     *
     * @return string
     */
    abstract public function getUuid(): string;

    /**
     * Return a JSON-serializable representation of the entity.
     *
     * @return array<string,mixed>
     */
    abstract public function jsonSerialize(): array;

}//end class
