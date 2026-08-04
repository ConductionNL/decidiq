<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Loaded by bootstrap-unit.php ONLY when the real OpenRegister app is not
 * installed. When it IS installed (CI installs it via the `additional-apps`
 * workflow input), Nextcloud's own autoloader resolves the class to the real
 * app and this file is never loaded.
 *
 * Because both environments occur in CI, this stub MUST mirror the real
 * class's contract. In particular:
 *
 *  - it extends OCP\AppFramework\Db\Entity, so the accessors decidesk uses
 *    (getUuid()/setUuid(), getRegister(), getSchema(), ...) resolve through
 *    Entity::__call() exactly as they do on the real class. Do NOT re-add
 *    explicit getUuid()/setUuid() methods here: on the real class they do not
 *    exist as real methods, so `createMock(ObjectEntity::class)->method('getUuid')`
 *    throws MethodCannotBeConfiguredException. A stub that declares them makes
 *    that broken test pass locally and fail on CI.
 *  - getObject() and jsonSerialize() are the only explicitly declared methods,
 *    matching the real class, and carry the real class's semantics.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Stub for ObjectEntity mirroring the real class's shape.
 */
class ObjectEntity extends Entity implements JsonSerializable
{

    /**
     * Object UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Object slug.
     *
     * @var string|null
     */
    protected ?string $slug = null;

    /**
     * Object URI.
     *
     * @var string|null
     */
    protected ?string $uri = null;

    /**
     * Object version.
     *
     * @var string|null
     */
    protected ?string $version = null;

    /**
     * Register slug or id.
     *
     * @var string|null
     */
    protected ?string $register = null;

    /**
     * Schema slug or id.
     *
     * @var string|null
     */
    protected ?string $schema = null;

    /**
     * The object payload.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $object = [];

    /**
     * Attached files.
     *
     * @var array<int,mixed>|null
     */
    protected ?array $files = [];

    /**
     * Object relations.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $relations = [];

    /**
     * Lock information.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $locked = null;

    /**
     * Owning user id.
     *
     * @var string|null
     */
    protected ?string $owner = null;

    /**
     * Per-object authorization block.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $authorization = [];

    /**
     * Folder path.
     *
     * @var string|null
     */
    protected ?string $folder = null;

    /**
     * Owning application.
     *
     * @var string|null
     */
    protected ?string $application = null;

    /**
     * Owning organisation.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Validation results.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $validation = [];

    /**
     * Soft-delete metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $deleted = [];

    /**
     * Geo metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $geo = [];

    /**
     * Retention metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $retention = [];

    /**
     * TMLO metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $tmlo = [];

    /**
     * Mail metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $mail = null;

    /**
     * Contacts metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $contacts = null;

    /**
     * Notes metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $notes = null;

    /**
     * Todos metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $todos = null;

    /**
     * Calendar metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $calendar = null;

    /**
     * Talk metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $talk = null;

    /**
     * Deck metadata.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $deck = null;

    /**
     * Serialised size.
     *
     * @var string|null
     */
    protected ?string $size = null;

    /**
     * Schema version.
     *
     * @var string|null
     */
    protected ?string $schemaVersion = null;

    /**
     * Last update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Display name.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Description.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Summary.
     *
     * @var string|null
     */
    protected ?string $summary = null;

    /**
     * Image reference.
     *
     * @var string|null
     */
    protected ?string $image = null;

    /**
     * Group grants.
     *
     * @var array<int,mixed>|null
     */
    protected ?array $groups = [];

    /**
     * Expiry timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $expires = null;


    /**
     * Register the field types, mirroring the real entity.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'uri', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'register', type: 'string');
        $this->addType(fieldName: 'schema', type: 'string');
        $this->addType(fieldName: 'object', type: 'json');
        $this->addType(fieldName: 'files', type: 'json');
        $this->addType(fieldName: 'relations', type: 'json');
        $this->addType(fieldName: 'locked', type: 'json');
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'folder', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'validation', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'json');
        $this->addType(fieldName: 'geo', type: 'json');
        $this->addType(fieldName: 'retention', type: 'json');
        $this->addType(fieldName: 'tmlo', type: 'json');
        $this->addType(fieldName: 'mail', type: 'json');
        $this->addType(fieldName: 'contacts', type: 'json');
        $this->addType(fieldName: 'notes', type: 'json');
        $this->addType(fieldName: 'todos', type: 'json');
        $this->addType(fieldName: 'calendar', type: 'json');
        $this->addType(fieldName: 'talk', type: 'json');
        $this->addType(fieldName: 'deck', type: 'json');
        $this->addType(fieldName: 'size', type: 'string');
        $this->addType(fieldName: 'schemaVersion', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'summary', type: 'string');
        $this->addType(fieldName: 'image', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'groups', type: 'json');
        $this->addType(fieldName: 'expires', type: 'datetime');

    }//end __construct()


    /**
     * Return [] instead of null for collection-shaped JSON fields.
     *
     * Mirrors the real entity's getter() override.
     *
     * @param string $name The property name.
     *
     * @return mixed The property value, or [] for unset array fields.
     */
    protected function getter(string $name): mixed
    {
        $arrayEmptyDefaults = [
            'files',
            'relations',
            'authorization',
            'validation',
            'deleted',
            'groups',
            'geo',
            'retention',
            'tmlo',
        ];

        if (in_array($name, $arrayEmptyDefaults) === true && property_exists($this, $name) === true) {
            return $this->$name ?? [];
        }

        return parent::getter(name: $name);

    }//end getter()


    /**
     * Return the object data with the UUID injected as 'id'.
     *
     * @return array<string,mixed>
     */
    public function getObject(): array
    {
        $objectData = $this->object ?? [];

        return array_merge(['id' => $this->uuid], $objectData);

    }//end getObject()


    /**
     * Return a JSON-serialisable representation of the entity.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $object = [];
        if (($this->object ?? null) !== null) {
            $object = $this->object;
        }

        if (($this->uuid ?? null) !== null) {
            $object['id'] = $this->uuid;
        }

        return $object;

    }//end jsonSerialize()


}//end class
