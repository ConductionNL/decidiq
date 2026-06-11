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
     * Matches the real ObjectService::find() signature with named parameters
     * for register and schema scope.
     *
     * @param int|string      $id       The object UUID or integer ID.
     * @param array|null      $_extend  Optional extend parameter.
     * @param bool            $files    Whether to include file information.
     * @param string|int|null $register Register slug or ID (optional).
     * @param string|int|null $schema   Schema slug or ID (optional).
     *
     * @return object|null
     */
    public function find(int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?object
    {
        return null;

    }//end find()


    /**
     * Find all objects matching config.
     *
     * @param array<string,mixed> $config Query configuration.
     *
     * @return array<int,object>
     */
    public function findAll(array $config=[]): array
    {
        return [];

    }//end findAll()


    /**
     * Save an object using named-parameter API.
     *
     * @param array<string,mixed> $object   The object data.
     * @param array|null          $extend   Extend options.
     * @param string|int|null     $register Register slug or ID.
     * @param string|int|null     $schema   Schema slug or ID.
     * @param string|null         $uuid     Object UUID.
     *
     * @return mixed ObjectEntity on success; tests may return array for legacy mock compatibility
     */
    public function saveObject(array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): mixed
    {
        return new \stdClass();

    }//end saveObject()


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
