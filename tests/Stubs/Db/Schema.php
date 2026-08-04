<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\Schema.
 *
 * decidesk never constructs a Schema — it always addresses schemas by slug.
 * The class exists here purely so that the ObjectService stub can declare the
 * SAME union parameter types production declares
 * (`Schema | string | int | null $schema`). Without it the stub would have to
 * narrow those parameters to `string|int|null`, which is exactly the kind of
 * looser-than-production signature decidesk#399 is about.
 *
 * Matched against ConductionNL/openregister@origin/development, lib/Db/Schema.php:
 *
 *   class Schema extends Entity implements JsonSerializable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Stub for Schema — a type anchor only.
 *
 * @method string|null getUuid()
 * @method string|null getSlug()
 */
class Schema extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the schema.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * URL-friendly identifier for the schema.
     *
     * @var string|null
     */
    protected ?string $slug = null;


    /**
     * Register the field types, as the production entity does.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('slug', 'string');

    }//end __construct()


    /**
     * Return a JSON-serialisable representation of the schema.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'   => $this->uuid,
            'slug' => $this->slug,
        ];

    }//end jsonSerialize()


}//end class
