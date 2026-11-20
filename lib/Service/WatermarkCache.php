<?php

/**
 * Decidiq Watermark Cache
 *
 * Keeps a stamped copy of a paper per paper version, reader and day in the
 * app's data folder, so a reader who opens the same paper twice on one day
 * waits for the stamp once. The name is
 * `<fileId>-<etag>-<reader hash>-<Y-m-d>.pdf`; the reader's uid is hashed so
 * no user id becomes a file name. Copies of earlier days are removed by
 * WatermarkCacheSweepJob.
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
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * The day cache of stamped papers.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class WatermarkCache {

	/**
	 * The folder in the app data the copies live in.
	 */
	public const FOLDER = 'watermark';

	/**
	 * Constructor.
	 *
	 * @param IAppData        $appData The app's data folder
	 * @param LoggerInterface $logger  Logger
	 */
	public function __construct(
		private readonly IAppData $appData,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name of a cached copy.
	 *
	 * @param int    $fileId The paper's file id
	 * @param string $etag   The paper version
	 * @param string $uid    The reader
	 * @param string $day    The day, Y-m-d
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function name(int $fileId, string $etag, string $uid, string $day): string {
		$version = preg_replace('/[^A-Za-z0-9]+/', '', $etag) ?? '';
		return $fileId . '-' . $version . '-' . hash('sha256', $uid) . '-' . $day . '.pdf';
	}//end name()

	/**
	 * The cached copy, or null.
	 *
	 * @param string $name The copy's name (see name())
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function get(string $name): ?string {
		try {
			return $this->appData->getFolder(self::FOLDER)->getFile($name)->getContent();
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: watermark cache unreadable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end get()

	/**
	 * Keep a copy. A cache that cannot be written only costs a second stamp.
	 *
	 * @param string $name    The copy's name (see name())
	 * @param string $content The stamped PDF
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function put(string $name, string $content): void {
		try {
			$this->folder()->newFile($name, $content);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: watermark cache not written', ['exception' => $e->getMessage()]);
		}
	}//end put()

	/**
	 * Remove every copy that is not of the given day.
	 *
	 * @param string $today The day to keep, Y-m-d
	 *
	 * @return int The number of copies removed
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function sweep(string $today): int {
		try {
			$folder = $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return 0;
		}

		$removed = 0;
		foreach ($folder->getDirectoryListing() as $file) {
			if (str_ends_with($file->getName(), '-' . $today . '.pdf') === true) {
				continue;
			}

			try {
				$file->delete();
				$removed++;
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: an old watermarked copy could not be removed', ['exception' => $e->getMessage()]);
			}
		}

		return $removed;
	}//end sweep()

	/**
	 * The cache folder, made when it is not there.
	 *
	 * @return ISimpleFolder
	 */
	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}//end folder()
}//end class
