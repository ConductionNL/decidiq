<?php
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * Provides the correct named-parameter method signatures so that VotingService
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
     * Fetch a single object by UUID (old fluent API).
     *
     * @param string $id Object UUID
     *
     * @return object|null
     */
    abstract public function find(string $id): ?object;

    /**
     * Update an object from an array patch (new named-parameter API).
     *
     * @param string              $id            Object UUID
     * @param array<string,mixed> $object        Patch data
     * @param bool                $updateVersion Whether to bump the object version
     * @param bool                $patch         Whether this is a partial update (PATCH)
     *
     * @return object|null
     */
    abstract public function updateFromArray(string $id, array $object, bool $updateVersion = false, bool $patch = false): ?object;

    /**
     * Fetch all objects matching filters.
     *
     * @param array<string,mixed> $params Query params including filters and pagination
     *
     * @return array<mixed>
     */
    abstract public function findAll(array $params = []): array;


    /**
     * Create a relation between two objects.
     *
     * @param string $sourceId       Source object UUID
     * @param string $targetId       Target object UUID
     * @param string $label          Relation label
     * @param string $sourceRegister Source register slug
     * @param string $sourceSchema   Source schema slug
     * @param string $targetRegister Target register slug
     * @param string $targetSchema   Target schema slug
     *
     * @return void
     */
    public function createRelation(
        string $sourceId,
        string $targetId,
        string $label='',
        string $sourceRegister='',
        string $sourceSchema='',
        string $targetRegister='',
        string $targetSchema='',
    ): void {
    }//end createRelation()

    /**
     * Fetch a single object directly (new named-parameter API).
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $uuid     Object UUID
     *
     * @return array<string,mixed>|null
     */
    abstract public function getObject(string $register, string $schema, string $uuid): ?array;

    /**
     * Query objects with filters (new named-parameter API).
     *
     * Returns an array with a 'results' key containing the matching objects.
     *
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param array<string,mixed> $filters  Field filters
     *
     * @return array{results: array<array<string,mixed>>}
     */
    abstract public function findObjects(string $register, string $schema, array $filters = []): array;

    /**
     * Save (create or update) an object.
     *
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param array<string,mixed> $object   Object data
     * @param string|null         $uuid     Existing UUID for updates (optional)
     *
     * @return array<string,mixed>|null
     */
    abstract public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): ?array;

}//end class
