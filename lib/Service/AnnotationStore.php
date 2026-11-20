<?php

/**
 * Decidiq Annotation Store
 *
 * Reads and writes annotation objects in system context for AnnotationService,
 * which decides who may see and change them before it calls this store. The
 * schema lets members read only their own annotations and write none.
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
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * System-context persistence of the annotation schema.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
class AnnotationStore {

	/**
	 * Most annotations read in one go.
	 */
	private const LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * One annotation, read past RBAC, or null when the id is empty or resolves to nothing.
	 *
	 * @param string $id The annotation
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function find(string $id): ?array {
		$annotation = null;
		if (trim($id) !== '') {
			try {
				$entity = $this->objectService->find(
					id: $id,
					options: new ObjectFindOptions(register: 'decidiq', schema: AnnotationService::SCHEMA),
					_rbac: false,
					_multitenancy: false
				);
			} catch (DoesNotExistException) {
				$entity = null;
			}

			if ($entity !== null) {
				$annotation = ['id' => (string)$entity->getUuid()] + $entity->getObject();
			}
		}

		return $annotation;
	}//end find()

	/**
	 * Read annotations in system context; callers filter what the user sees.
	 *
	 * @param array<string, mixed> $filters Property filters
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function rows(array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => (['register' => 'decidiq', 'schema' => AnnotationService::SCHEMA] + $filters), 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = ['id' => (string)$row->getUuid()] + (array)$row->getObject();
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Save in system context, after the checks above.
	 *
	 * @param array<string, mixed> $annotation The complete annotation
	 * @param string|null          $uuid       The id, null for a new one
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function save(array $annotation, ?string $uuid): array {
		$saved = $this->objectService->saveObject(
			object: $annotation,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: AnnotationService::SCHEMA), uuid: $uuid),
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => (string)$saved->getUuid()] + $saved->getObject();
	}//end save()

	/**
	 * Delete annotations in system context, after the caller's checks.
	 *
	 * @param list<string> $ids The annotations
	 *
	 * @return int How many were deleted.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function removeAll(array $ids): int {
		foreach ($ids as $id) {
			$this->objectService->deleteObject(
				new ObjectDeletion(uuid: $id, target: new ObjectFindTarget(register: 'decidiq', schema: AnnotationService::SCHEMA)),
				_rbac: false,
				_multitenancy: false
			);
		}

		return count($ids);
	}//end removeAll()
}//end class
