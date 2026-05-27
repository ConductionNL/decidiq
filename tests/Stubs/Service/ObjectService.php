<?php
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * Provides the correct named-parameter method signatures so that Decidesk
 * unit tests can mock the object service without PHP throwing
 * "Unknown named parameter" errors at test runtime.
 *
 * This file is loaded by tests/bootstrap-unit.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for ObjectService with proper named-parameter method signatures.
 */
abstract class ObjectService
{

    /**
     * Set active register (fluent, old API).
     *
     * @param string $register Register slug
     *
     * @return static
     */
    abstract public function setRegister(string $register): static;

    /**
     * Set active schema (fluent, old API).
     *
     * @param string $schema Schema slug
     *
     * @return static
     */
    abstract public function setSchema(string $schema): static;

    /**
     * Fetch a single object by UUID.
     *
     * Matches the real ObjectService::find() signature with named parameters
     * for register and schema scope.
     *
     * @param int|string      $id       Object UUID or integer ID
     * @param array|null      $_extend  Properties to extend the object with
     * @param bool            $files    Whether to include file information
     * @param string|int|null $register Register slug or ID (optional)
     * @param string|int|null $schema   Schema slug or ID (optional)
     *
     * @return object|null
     */
    abstract public function find(int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?object;

    /**
     * Fetch all objects matching filters.
     *
     * @param array<string,mixed> $config Query configuration
     *
     * @return array<mixed>
     */
    abstract public function findAll(array $config=[]): array;

    /**
     * Save (create or update) an object.
     *
     * Matches the real ObjectService::saveObject() named-parameter API.
     *
     * @param array<string,mixed> $object   Object data
     * @param array|null          $extend   Extend options
     * @param string|int|null     $register Register slug or ID
     * @param string|int|null     $schema   Schema slug or ID
     * @param string|null         $uuid     Existing UUID for updates
     *
     * @return mixed ObjectEntity on success; tests may return array for legacy mock compatibility
     */
    abstract public function saveObject(array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): mixed;

}//end class
