<?php
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * Loaded by bootstrap-unit.php ONLY when the real OpenRegister app is not
 * installed. When it IS installed (CI installs it via the `additional-apps`
 * workflow input), Nextcloud's own autoloader resolves the class to the real
 * app and this file is never loaded.
 *
 * Because both environments occur in CI, the signatures below MUST match the
 * real OCA\OpenRegister\Service\ObjectService. In particular find() returns
 * ?ObjectEntity and saveObject() returns ObjectEntity — NOT ?object / mixed.
 * A loose stub lets tests configure mocks to return \stdClass doubles that the
 * real class rejects with a TypeError, which is exactly the divergence this
 * stub previously hid.
 *
 * Parameter unions are narrowed where the real class accepts OpenRegister
 * entity classes (Register / Schema) that have no stub — decidesk only ever
 * passes slugs or ids, so string|int is a faithful subset.
 *
 * This file is NOT scanned by PHPCS (phpcs.xml only covers lib/).
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stub for ObjectService with the real named-parameter method signatures.
 */
abstract class ObjectService
{

    /**
     * Set active register (fluent).
     *
     * @param string|int $register Register slug or ID
     *
     * @return static
     */
    abstract public function setRegister(string|int $register): static;

    /**
     * Set active schema (fluent).
     *
     * @param string|int $schema Schema slug or ID
     *
     * @return static
     */
    abstract public function setSchema(string|int $schema): static;

    /**
     * Fetch a single object by UUID.
     *
     * @param int|string      $id             Object UUID or integer ID
     * @param array|null      $_extend        Properties to extend the object with
     * @param bool            $files          Whether to include file information
     * @param string|int|null $register       Register slug or ID (optional)
     * @param string|int|null $schema         Schema slug or ID (optional)
     * @param bool            $_rbac          Whether to apply RBAC
     * @param bool            $_multitenancy  Whether to apply multitenancy scoping
     * @param bool            $_render        Whether to render the entity
     *
     * @return ObjectEntity|null
     */
    abstract public function find(
        int|string $id,
        ?array $_extend=[],
        bool $files=false,
        string|int|null $register=null,
        string|int|null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_render=true
    ): ?ObjectEntity;

    /**
     * Fetch all objects matching filters.
     *
     * @param array<string,mixed> $config        Query configuration
     * @param bool                $_rbac         Whether to apply RBAC
     * @param bool                $_multitenancy Whether to apply multitenancy scoping
     *
     * @return array<mixed>
     */
    abstract public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Save (create or update) an object.
     *
     * @param array<string,mixed>|ObjectEntity $object   Object data or entity
     * @param array|null                       $extend   Extend options
     * @param string|int|null                  $register Register slug or ID
     * @param string|int|null                  $schema   Schema slug or ID
     * @param string|null                      $uuid     Existing UUID for updates
     *
     * @return ObjectEntity
     */
    abstract public function saveObject(
        array|ObjectEntity $object,
        ?array $extend=[],
        string|int|null $register=null,
        string|int|null $schema=null,
        ?string $uuid=null
    ): ObjectEntity;

    /**
     * Delete (soft-archive) an object by UUID.
     *
     * @param string          $uuid     Object UUID
     * @param string|int|null $register Register slug or ID
     * @param string|int|null $schema   Schema slug or ID
     *
     * @return bool
     */
    abstract public function deleteObject(
        string $uuid,
        string|int|null $register=null,
        string|int|null $schema=null
    ): bool;

}//end class
