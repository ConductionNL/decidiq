<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * Defines the class in the correct namespace so that unit tests can mock
 * ObjectService without requiring the OpenRegister app to be installed.
 *
 * This file is loaded by tests/bootstrap.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub implementation of ObjectService for unit testing.
 *
 * Provides only the method signatures used by Decidesk unit tests.
 * PHPUnit's createMock() will override all methods.
 */
class ObjectService
{

    /**
     * Find an object by ID.
     *
     * @param string $id     The object UUID.
     * @param mixed  $extend Optional extend parameter.
     * @param string $register Register slug.
     * @param string $schema  Schema slug.
     *
     * @return object|null
     */
    public function find(string $id, mixed $extend=null, string $register='', string $schema=''): ?object
    {
        return null;

    }//end find()


    /**
     * Find all objects matching params.
     *
     * @param array<string,mixed> $params Query parameters.
     *
     * @return array<int,mixed>
     */
    public function findAll(array $params=[]): array
    {
        return [];

    }//end findAll()


    /**
     * Save an object.
     *
     * @param array<string,mixed> $object   The object data.
     * @param string              $register Register slug.
     * @param string              $schema   Schema slug.
     * @param string              $uuid     Object UUID.
     *
     * @return array<string,mixed>
     */
    public function saveObject(array $object, string $register='', string $schema='', string $uuid=''): array
    {
        return [];

    }//end saveObject()


    /**
     * Create a relation between two objects.
     *
     * @param string $sourceId       Source object UUID.
     * @param string $targetId       Target object UUID.
     * @param string $label          Relation label.
     * @param string $sourceRegister Source register slug.
     * @param string $sourceSchema   Source schema slug.
     * @param string $targetRegister Target register slug.
     * @param string $targetSchema   Target schema slug.
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
     * Update an object from array.
     *
     * @param string              $id            Object UUID.
     * @param array<string,mixed> $object        Updated data.
     * @param bool                $updateVersion Whether to bump version.
     * @param bool                $patch         Whether to patch (partial update).
     *
     * @return object|null
     */
    public function updateFromArray(string $id, array $object, bool $updateVersion=false, bool $patch=false): ?object
    {
        return null;

    }//end updateFromArray()


    /**
     * Set the active register context.
     *
     * @param string $register Register slug.
     *
     * @return static
     */
    public function setRegister(string $register): static
    {
        return $this;

    }//end setRegister()


    /**
     * Set the active schema context.
     *
     * @param string $schema Schema slug.
     *
     * @return static
     */
    public function setSchema(string $schema): static
    {
        return $this;

    }//end setSchema()


}//end class
