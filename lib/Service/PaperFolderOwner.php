<?php

/**
 * Finds the meeting or agenda item a paper's folder belongs to.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister names an object's folder after its uuid, so a file's parent
 * folder name is the object id. The object is looked up before it is named
 * the owner, so a user's own folder that happens to carry a uuid name is
 * left alone, and the schema is read off the object, never assumed.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */
class PaperFolderOwner {

	/**
	 * The schemas whose folders hold papers.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['agenda-item', 'meeting'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService  The OpenRegister object service.
	 * @param ListenerSchemaResolverInterface $schemaResolver Reads an object's schema slug.
	 * @param LoggerInterface                 $logger         The logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The meeting or agenda item whose folder holds a file, or null.
	 *
	 * @param File $node The file.
	 *
	 * @return array{objectId: string, schema: string, object: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
	 */
	public function ownerOf(File $node): ?array {
		try {
			$folderName = $node->getParent()->getName();
		} catch (\Throwable) {
			return null;
		}

		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $folderName) !== 1) {
			return null;
		}

		try {
			$found = $this->objectService->find(id: $folderName, register: 'decidiq', _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: no decidiq object for a paper folder', ['folder' => $folderName, 'error' => $e->getMessage()]);
			return null;
		}

		if ($found === null) {
			return null;
		}

		$object = (array)$found->getObject();
		$slug   = $this->schemaResolver->schemaSlug(entity: $found, row: $object);
		if (in_array($slug, self::SCHEMAS, true) === false) {
			return null;
		}

		return ['objectId' => $folderName, 'schema' => $slug, 'object' => $object];
	}//end ownerOf()
}//end class
