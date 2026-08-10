<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\Register.
 *
 * decidesk never constructs a Register — it always addresses registers by slug.
 * The class exists here purely so that the ObjectService stub can declare the
 * SAME union parameter types production declares
 * (`Register | string | int | null $register`). Without it the stub would have
 * to narrow those parameters to `string|int|null`, which is exactly the kind of
 * looser-than-production signature decidesk#399 is about.
 *
 * Matched against ConductionNL/openregister@origin/development, lib/Db/Register.php:
 *
 *   class Register extends Entity implements JsonSerializable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Stub for Register — a type anchor only.
 *
 * @method string|null getUuid()
 * @method string|null getSlug()
 * @method string|null getTitle()
 */
class Register extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the register.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * URL-friendly identifier for the register.
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
     * Return a JSON-serialisable representation of the register.
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
