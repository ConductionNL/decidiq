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
     * Accepts either the old single-array options form used by legacy callers
     * (AgendaService, MotionService) or the new named-parameter form used
     * by newer code (DecisionsSearchProvider). The first argument therefore
     * accepts either an array of options or an int limit.
     *
     * @param array<string,mixed>|int|null $limit   Optional limit or legacy options array.
     * @param int|null                     $offset  Optional offset.
     * @param array<mixed>                 $order   Ordering specification.
     * @param array<string,mixed>          $filters Field filters.
     *
     * @return array<mixed>
     */
    abstract public function findAll(array|int|null $limit = null, ?int $offset = null, array $order = [], array $filters = []): array;

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
