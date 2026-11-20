<?php

/**
 * Reads and writes one decidiq object in system context.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * One object of the decidiq register, read or written past RBAC and
 * multitenancy. For services that guard access themselves before they call it.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */
final class SystemObjectStore {

	/**
	 * The register the objects live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The object as OpenRegister serialises it, or null when the id is empty
	 * or resolves to nothing.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param string                 $schema        The schema slug
	 * @param string                 $id            The uuid
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public static function read(ObjectServiceInterface $objectService, string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $objectService->find(
			id: $id,
			options: new ObjectFindOptions(register: self::REGISTER, schema: $schema),
			_rbac: false,
			_multitenancy: false
		);
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end read()

	/**
	 * The object's own data, without its `id` and `@self` keys, or null as {@see self::read()}.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param string                 $schema        The schema slug
	 * @param string                 $id            The uuid
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public static function readData(ObjectServiceInterface $objectService, string $schema, string $id): ?array {
		$data = self::read(objectService: $objectService, schema: $schema, id: $id);
		if ($data === null) {
			return null;
		}

		unset($data['id'], $data['@self']);
		return $data;
	}//end readData()

	/**
	 * Save the object (create without a uuid, replace with one) and return it
	 * with its uuid as `id` and without `@self`.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param string                 $schema        The schema slug
	 * @param array<string, mixed>   $object        The object
	 * @param string|null            $uuid          The uuid when replacing
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public static function write(ObjectServiceInterface $objectService, string $schema, array $object, ?string $uuid): array {
		$saved = $objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: $schema),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
