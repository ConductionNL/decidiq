<?php

/**
 * Contract-shaped base for OpenRegister ObjectService test doubles.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Tests\Doubles;

use LogicException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;

/**
 * Base class for hand-written ObjectService doubles.
 *
 * ADR-084 published `ObjectServiceInterface`, so every collaborator that used
 * to accept a duck-typed `object` now type-hints the contract. A hand-written
 * double therefore has to satisfy all 25 methods, not just the three or four a
 * given test exercises.
 *
 * This base implements the whole surface and makes every method **fail loudly**
 * when it is reached. A test that starts calling a method it never stubbed gets
 * a named exception naming the method, rather than a silent `null` that reads
 * like a passing assertion. Subclasses override only what they mean to exercise
 * — and their signatures are checked against the contract by PHP itself, which
 * is exactly the drift that broke this suite (procest#855).
 */
abstract class ObjectServiceContractDouble implements ObjectServiceInterface {

	/**
	 * Refuse a method the concrete double did not stub.
	 *
	 * @param string $method The contract method that was reached.
	 *
	 * @return never
	 */
	final protected function notStubbed(string $method): never {
		throw new LogicException(
			static::class . ' does not stub ObjectServiceInterface::' . $method . '()'
		);
	}//end notStubbed()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed>       $object        Object body.
	 * @param array<string,mixed>|null  $extend        Extend directives.
	 * @param string|int|null           $register      Register id or slug.
	 * @param string|int|null           $schema        Schema id or slug.
	 * @param string|null               $uuid          Explicit identity.
	 * @param bool                      $_rbac         RBAC toggle.
	 * @param bool                      $_multitenancy Tenancy toggle.
	 * @param bool                      $silent        Suppress events.
	 * @param bool                      $_validation   Validation toggle.
	 * @param array<string,mixed>|null  $uploadedFiles Uploaded files.
	 * @param IUser|null                $currentUser   Acting user.
	 * @param bool                      $failIfExists  Refuse an update.
	 *
	 * @return ObjectEntityInterface
	 */
	public function saveObject(
		array $object,
		?array $extend=[],
		string|int|null $register=null,
		string|int|null $schema=null,
		?string $uuid=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $silent=false,
		bool $_validation=true,
		?array $uploadedFiles=null,
		?IUser $currentUser=null,
		bool $failIfExists=false
	): ObjectEntityInterface {
		$this->notStubbed('saveObject');
	}//end saveObject()

	/**
	 * {@inheritDoc}
	 *
	 * @param string|int $register Register id or slug.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	/**
	 * {@inheritDoc}
	 *
	 * @param string|int $schema Schema id or slug.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param int|string               $id            Object identifier.
	 * @param array<string,mixed>|null $_extend       Extend directives.
	 * @param bool                     $files         Include files.
	 * @param string|int|null          $register      Register id or slug.
	 * @param string|int|null          $schema        Schema id or slug.
	 * @param bool                     $_rbac         RBAC toggle.
	 * @param bool                     $_multitenancy Tenancy toggle.
	 * @param bool                     $_render       Render toggle.
	 * @param bool                     $_audit        Audit toggle.
	 *
	 * @return ObjectEntityInterface|null
	 */
	public function find(
		int|string $id,
		?array $_extend=[],
		bool $files=false,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $_render=true,
		bool $_audit=true
	): ?ObjectEntityInterface {
		$this->notStubbed('find');
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $config        Query config.
	 * @param bool                $_rbac         RBAC toggle.
	 * @param bool                $_multitenancy Tenancy toggle.
	 *
	 * @return array<int,mixed>
	 */
	public function findAll(
		array $config=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array {
		$this->notStubbed('findAll');
	}//end findAll()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed>    $query         Search query.
	 * @param bool                   $_rbac         RBAC toggle.
	 * @param bool                   $_multitenancy Tenancy toggle.
	 * @param array<int,string>|null $ids           Restrict to ids.
	 * @param string|null            $uses          Uses filter.
	 * @param array<int,string>|null $views         Views filter.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjects(
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true,
		?array $ids=null,
		?string $uses=null,
		?array $views=null
	): array|int {
		$this->notStubbed('searchObjects');
	}//end searchObjects()

	/**
	 * {@inheritDoc}
	 *
	 * @param string          $uuid            Object uuid.
	 * @param string|int|null $register        Register id or slug.
	 * @param string|int|null $schema          Schema id or slug.
	 * @param bool            $_rbac           RBAC toggle.
	 * @param bool            $_multitenancy   Tenancy toggle.
	 * @param bool            $_retentionSweep Retention sweep flag.
	 * @param IUser|null      $currentUser     Acting user.
	 * @param bool            $permanent       Hard delete.
	 *
	 * @return bool
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $_retentionSweep=false,
		?IUser $currentUser=null,
		bool $permanent=false
	): bool {
		$this->notStubbed('deleteObject');
	}//end deleteObject()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed>    $query         Search query.
	 * @param bool                   $_rbac         RBAC toggle.
	 * @param bool                   $_multitenancy Tenancy toggle.
	 * @param bool                   $deleted       Include deleted.
	 * @param array<int,string>|null $ids           Restrict to ids.
	 * @param string|null            $uses          Uses filter.
	 * @param array<int,string>|null $views         Views filter.
	 *
	 * @return array<string,mixed>
	 */
	public function searchObjectsPaginated(
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $deleted=false,
		?array $ids=null,
		?string $uses=null,
		?array $views=null
	): array {
		$this->notStubbed('searchObjectsPaginated');
	}//end searchObjectsPaginated()

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $registerSlug  Register slug.
	 * @param string              $schemaSlug    Schema slug.
	 * @param array<string,mixed> $filters       Filters.
	 * @param bool                $_rbac         RBAC toggle.
	 * @param bool                $_multitenancy Tenancy toggle.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjectsBySlug(
		string $registerSlug,
		string $schemaSlug,
		array $filters=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array|int {
		$this->notStubbed('searchObjectsBySlug');
	}//end searchObjectsBySlug()

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function clearCurrents(): void {
	}//end clearCurrents()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed>              $requestParams Request params.
	 * @param int|string|array<int,mixed>|null $register      Register.
	 * @param int|string|array<int,mixed>|null $schema        Schema.
	 * @param array<int,string>|null           $ids           Restrict to ids.
	 *
	 * @return array<string,mixed>
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register=null,
		int|string|array|null $schema=null,
		?array $ids=null
	): array {
		$this->notStubbed('buildSearchQuery');
	}//end buildSearchQuery()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int,mixed> $objects        Objects to save.
	 * @param string|int|null  $register       Register id or slug.
	 * @param string|int|null  $schema         Schema id or slug.
	 * @param bool             $_rbac          RBAC toggle.
	 * @param bool             $_multitenancy  Tenancy toggle.
	 * @param bool             $validation     Validation toggle.
	 * @param bool             $events         Emit events.
	 * @param bool             $deduplicateIds Deduplicate ids.
	 * @param bool             $enrich         Enrich results.
	 * @param bool             $_audit         Audit toggle.
	 *
	 * @return array<int,mixed>
	 */
	public function saveObjects(
		array $objects,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $validation=false,
		bool $events=false,
		bool $deduplicateIds=true,
		bool $enrich=true,
		bool $_audit=true
	): array {
		$this->notStubbed('saveObjects');
	}//end saveObjects()

	/**
	 * {@inheritDoc}
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation) {
		return $operation();
	}//end runAsSystem()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $config Query config.
	 *
	 * @return int
	 */
	public function count(array $config=[]): int {
		$this->notStubbed('count');
	}//end count()

	/**
	 * {@inheritDoc}
	 *
	 * @param string|int $identifier Object identifier.
	 * @param bool       $advisory   Advisory lock.
	 *
	 * @return bool
	 */
	public function unlockObject(string|int $identifier, bool $advisory=false): bool {
		$this->notStubbed('unlockObject');
	}//end unlockObject()

	/**
	 * {@inheritDoc}
	 *
	 * @param string      $identifier Object identifier.
	 * @param string|null $process    Process label.
	 * @param int|null    $duration   Lock duration.
	 * @param bool        $advisory   Advisory lock.
	 *
	 * @return array<string,mixed>
	 */
	public function lockObject(
		string $identifier,
		?string $process=null,
		?int $duration=null,
		bool $advisory=false
	): array {
		$this->notStubbed('lockObject');
	}//end lockObject()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int,string> $uuids         Object uuids.
	 * @param bool              $_rbac         RBAC toggle.
	 * @param bool              $_multitenancy Tenancy toggle.
	 *
	 * @return array<string,mixed>
	 */
	public function deleteObjects(
		array $uuids=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array {
		$this->notStubbed('deleteObjects');
	}//end deleteObjects()

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $uuid          Object uuid.
	 * @param array<string,mixed> $filters       Log filters.
	 * @param bool                $_rbac         RBAC toggle.
	 * @param bool                $_multitenancy Tenancy toggle.
	 *
	 * @return array<int,mixed>
	 */
	public function getLogs(
		string $uuid,
		array $filters=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array {
		$this->notStubbed('getLogs');
	}//end getLogs()

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $objectId      Object identifier.
	 * @param array<string,mixed> $data          Patch data.
	 * @param bool                $_rbac         RBAC toggle.
	 * @param bool                $_multitenancy Tenancy toggle.
	 *
	 * @return ObjectEntityInterface
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $_rbac=true,
		bool $_multitenancy=true
	): ObjectEntityInterface {
		$this->notStubbed('updateObject');
	}//end updateObject()

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $objectId      Object identifier.
	 * @param array<string,mixed> $query         Query.
	 * @param bool                $_rbac         RBAC toggle.
	 * @param bool                $_multitenancy Tenancy toggle.
	 *
	 * @return array<int,mixed>
	 */
	public function getObjectUses(
		string $objectId,
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array {
		$this->notStubbed('getObjectUses');
	}//end getObjectUses()

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $objectId      Object identifier.
	 * @param array<string,mixed> $query         Query.
	 * @param bool                $_rbac         RBAC toggle.
	 * @param bool                $_multitenancy Tenancy toggle.
	 *
	 * @return array<int,mixed>
	 */
	public function getObjectUsedBy(
		string $objectId,
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array {
		$this->notStubbed('getObjectUsedBy');
	}//end getObjectUsedBy()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $search       Search term.
	 * @param bool   $partialMatch Partial match.
	 *
	 * @return array<int,mixed>
	 */
	public function findByRelations(string $search, bool $partialMatch=true): array {
		$this->notStubbed('findByRelations');
	}//end findByRelations()

	/**
	 * {@inheritDoc}
	 *
	 * @param string                   $id            Object identifier.
	 * @param array<string,mixed>|null $_extend       Extend directives.
	 * @param bool                     $files         Include files.
	 * @param string|int|null          $register      Register id or slug.
	 * @param string|int|null          $schema        Schema id or slug.
	 * @param bool                     $_rbac         RBAC toggle.
	 * @param bool                     $_multitenancy Tenancy toggle.
	 *
	 * @return ObjectEntityInterface
	 */
	public function findSilent(
		string $id,
		?array $_extend=[],
		bool $files=false,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true
	): ObjectEntityInterface {
		$this->notStubbed('findSilent');
	}//end findSilent()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed>    $query         Search query.
	 * @param bool                   $_rbac         RBAC toggle.
	 * @param bool                   $_multitenancy Tenancy toggle.
	 * @param array<int,string>|null $ids           Restrict to ids.
	 * @param string|null            $uses          Uses filter.
	 *
	 * @return int
	 */
	public function countSearchObjects(
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true,
		?array $ids=null,
		?string $uses=null
	): int {
		$this->notStubbed('countSearchObjects');
	}//end countSearchObjects()

	/**
	 * {@inheritDoc}
	 *
	 * @return ObjectEntityInterface|null
	 */
	public function getObject(): ?ObjectEntityInterface {
		$this->notStubbed('getObject');
	}//end getObject()
}//end class
