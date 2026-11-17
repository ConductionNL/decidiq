<?php

/**
 * Folder, file and body-name steps shared by the services that file documents
 * into a governance body's Files tree.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\Files\NotFoundException;
use Throwable;

/**
 * Creates a nested folder path, writes a file into a folder and resolves a
 * governance body's display name.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
final class MeetingFileTree {

	/**
	 * Create every folder of the path, outermost first, and return the path.
	 * OpenRegister's createFolder() is idempotent, so existing folders stay.
	 *
	 * @param object             $fileService OpenRegister's FileService
	 * @param array<int, string> $segments    The path segments, outermost first
	 *
	 * @return string The path, the segments joined with `/`
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public static function createPath(object $fileService, array $segments): string {
		$path = '';
		foreach ($segments as $segment) {
			$prefix = $path;
			$path = $segment;
			if ($prefix !== '') {
				$path = $prefix . '/' . $segment;
			}

			$fileService->createFolder($path);
		}

		return $path;
	}//end createPath()

	/**
	 * Write the content into the folder under the name, replacing the content
	 * of a file that already has that name.
	 *
	 * @param object $folderNode The folder
	 * @param string $name       The file name
	 * @param string $content    The content
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public static function putFile(object $folderNode, string $name, string $content): void {
		try {
			$existing = $folderNode->get($name);
			$existing->putContent($content);
		} catch (NotFoundException) {
			$folderNode->newFile($name, $content);
		}
	}//end putFile()

	/**
	 * The name (else the title) of the governance body a reference points at,
	 * or '' when the reference is empty or the body cannot be read.
	 *
	 * @param object $objectService OpenRegister's ObjectService
	 * @param mixed  $bodyId        The reference: a uuid, or a relation array with an `id`
	 *
	 * @return string
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public static function bodyName(object $objectService, mixed $bodyId): string {
		if (is_array($bodyId) === true) {
			$bodyId = ($bodyId['id'] ?? null);
		}

		if (is_string($bodyId) === false || $bodyId === '') {
			return '';
		}

		try {
			$entity = $objectService->find(id: $bodyId, options: new ObjectFindOptions(register: 'decidiq', schema: 'governance-body'));
		} catch (Throwable) {
			return '';
		}

		if ($entity === null) {
			return '';
		}

		$body = (array)$entity->jsonSerialize();
		return (string)($body['name'] ?? ($body['title'] ?? ''));
	}//end bodyName()
}//end class
