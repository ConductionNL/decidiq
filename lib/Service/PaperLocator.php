<?php

/**
 * Decidiq Paper Locator
 *
 * Finds one paper of a meeting or an agenda item for PaperController, once
 * the controller has read the object under the caller's own rights: the
 * check that the file is one of its papers, the file itself, and the meeting
 * whose watermark policy applies.
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
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Locates a paper and the meeting it belongs to.
 *
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class PaperLocator {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param IRootFolder            $rootFolder    Reads the paper
	 * @param LoggerInterface        $logger        Logger
	 * @param IUserMountCache        $mountCache    Names the account a stored paper lives in
	 *
	 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
		private readonly IUserMountCache $mountCache,
	) {
	}//end __construct()

	/**
	 * The paper with its meeting, or null when the file is not one of the
	 * object's papers or the file is gone.
	 *
	 * @param string               $schema   `agenda-item` or `meeting`
	 * @param string               $objectId The object uuid
	 * @param array<string, mixed> $data     The object, as the caller read it
	 * @param int                  $fileId   The paper's file id
	 *
	 * @return array{meeting: array<string, mixed>, agendaItemId: ?string, file: File, fileId: int}|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function locate(string $schema, string $objectId, array $data, int $fileId): ?array {
		$file = $this->fileById(fileId: $fileId);
		if ($file === null) {
			return null;
		}

		if ($this->recordsPaper(data: $data, fileId: $fileId) === false && self::inObjectFolder(file: $file, objectId: $objectId) === false) {
			return null;
		}

		if ($schema === 'agenda-item') {
			return ['meeting' => $this->meetingOf(item: $data), 'agendaItemId' => $objectId, 'file' => $file, 'fileId' => $fileId];
		}

		return ['meeting' => $data, 'agendaItemId' => null, 'file' => $file, 'fileId' => $fileId];
	}//end locate()

	/**
	 * The meeting of an agenda item the caller may read, or an empty meeting.
	 *
	 * An empty meeting is not public, so the paper is stamped: a meeting that
	 * cannot be read never lets a paper out unstamped.
	 *
	 * @param array<string, mixed> $item The agenda item
	 *
	 * @return array<string, mixed>
	 */
	private function meetingOf(array $item): array {
		$meetingId = trim((string)($item['meeting'] ?? ''));
		if ($meetingId === '') {
			return [];
		}

		try {
			$meeting = $this->objectService->find(
				id: $meetingId,
				options: new ObjectFindOptions(register: 'decidiq', schema: 'meeting'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: meeting of a paper unreadable; stamping it', ['meetingId' => $meetingId, 'exception' => $e->getMessage()]);
			return [];
		}

		if ($meeting === null) {
			return [];
		}

		return $meeting->getObject();
	}//end meetingOf()

	/**
	 * Whether the object records the paper, as its PDF or as its original.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param int                  $fileId The file id
	 *
	 * @return bool
	 */
	private function recordsPaper(array $data, int $fileId): bool {
		foreach ((array)($data['paperRenditions'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ((int)($entry['pdfFileId'] ?? 0) === $fileId || (int)($entry['sourceFileId'] ?? 0) === $fileId) {
				return true;
			}
		}

		return false;
	}//end recordsPaper()

	/**
	 * The file by its id, wherever it is stored.
	 *
	 * The root folder only finds a file on a storage that is set up in this
	 * request; OpenRegister keeps an object's files in its own account, which
	 * usually is not. The mounts Nextcloud knows for the id name the account,
	 * and reading through that account's folder sets its storage up.
	 *
	 * @param int $fileId The file id
	 *
	 * @return File|null
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
	 */
	private function fileById(int $fileId): ?File {
		$file = $this->rootFolder->getFirstNodeById($fileId);
		if ($file instanceof File) {
			return $file;
		}

		foreach ($this->mountCache->getMountsForFileId($fileId) as $mount) {
			try {
				$file = $this->rootFolder->getUserFolder($mount->getUser()->getUID())->getFirstNodeById($fileId);
			} catch (\Throwable $e) {
				$this->logger->debug('Decidiq: paper not reachable through a mount', ['fileId' => $fileId, 'exception' => $e->getMessage()]);
				continue;
			}

			if ($file instanceof File) {
				return $file;
			}
		}

		return null;
	}//end fileById()

	/**
	 * Whether the file sits in the object's own folder: OpenRegister keeps
	 * the files attached to an object in a folder named after its uuid, so a
	 * PDF attached to the item directly is one of its papers too.
	 *
	 * @param File   $file     The file
	 * @param string $objectId The object uuid
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
	 */
	private static function inObjectFolder(File $file, string $objectId): bool {
		if (trim($objectId) === '') {
			return false;
		}

		try {
			return strcasecmp($file->getParent()->getName(), trim($objectId)) === 0;
		} catch (\Throwable) {
			return false;
		}
	}//end inObjectFolder()
}//end class
