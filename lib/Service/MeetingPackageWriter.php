<?php

/**
 * Decidiq Meeting Package Writer
 *
 * Writes one meeting package into Files: the package folder, its table of
 * contents and one folder per agenda item holding the item's documents.
 * A file that cannot be written is skipped and noted, not fatal.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Writes the files of one meeting package and keeps its tally.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
final class MeetingPackageWriter {

	/**
	 * OpenRegister FileService of the current write.
	 *
	 * @var object|null
	 */
	private ?object $fileService = null;

	/**
	 * Documents written so far.
	 *
	 * @var int
	 */
	private int $files = 0;

	/**
	 * Files skipped so far, with the reason.
	 *
	 * @var string[]
	 */
	private array $skipped = [];

	/**
	 * Constructor for MeetingPackageWriter.
	 *
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the package folder, its table of contents and every item's documents.
	 *
	 * @param object $fileService OpenRegister FileService
	 * @param array<string, mixed> $package The items, packagePath and the table of contents under `toc`
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return void
	 */
	public function write(object $fileService, array $package): void {
		$this->fileService = $fileService;
		$packageFolder = $fileService->createFolder($package['packagePath']);
		$this->writeFileDefensively(folder: $packageFolder, file: ['name' => MeetingPackageService::TOC_FILENAME, 'content' => $package['toc']]);
		$number = 0;
		foreach ($package['items'] as $item) {
			$number++;
			$this->copyItemDocuments(item: $item, place: ['packagePath' => $package['packagePath'], 'number' => $number]);
		}

	}//end write()

	/**
	 * The documents written and the files skipped so far.
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array{files: int, skipped: string[]}
	 */
	public function tally(): array {
		return ['files' => $this->files, 'skipped' => $this->skipped];

	}//end tally()

	/**
	 * Copy one agenda item's documents into its numbered folder.
	 *
	 * @param array<string, mixed> $item Agenda item payload
	 * @param array{packagePath: string, number: int} $place The package path and the item's position
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return void
	 */
	private function copyItemDocuments(array $item, array $place): void {
		$itemFolderName = $this->itemFolderName(number: $place['number'], item: $item);
		$itemFolder = $this->fileService->createFolder($place['packagePath'] . '/' . $itemFolderName);
		foreach ($this->resolveItemFiles(item: $item) as $fileNode) {
			try {
				$file = ['name' => (string)$fileNode->getName(), 'content' => (string)$fileNode->getContent()];
				$this->files += (int)$this->writeFileDefensively(folder: $itemFolder, file: $file);
			} catch (\Throwable $copyError) {
				$this->skipped[] = $itemFolderName . '/' . $fileNode->getName() . ' (' . $copyError->getMessage() . ')';
			}
		}

	}//end copyItemDocuments()

	/**
	 * An agenda item's documents that can be copied, preferring a PDF
	 * rendition over its source.
	 *
	 * @param array<string, mixed> $item Agenda item payload
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<int, object>
	 */
	private function resolveItemFiles(array $item): array {
		$itemId = (string)($item['id'] ?? ($item['@self']['id'] ?? ''));
		if ($itemId === '') {
			return [];
		}

		try {
			$nodes = $this->fileService->getFiles($itemId);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: package file resolution skipped for agenda item', ['itemId' => $itemId, 'error' => $e->getMessage()]);
			return [];
		}

		$nodes = array_values(array_filter((array)$nodes, fn (mixed $node): bool => $this->isCopyable(node: $node)));
		return (new PaperRenditionFilter())->preferPdf(nodes: $nodes, renditions: (array)($item['paperRenditions'] ?? []));

	}//end resolveItemFiles()

	/**
	 * Whether a node is a file whose name and content can be read.
	 *
	 * @param mixed $node The node
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return bool
	 */
	private function isCopyable(mixed $node): bool {
		return is_object($node) === true && method_exists($node, 'getName') === true && method_exists($node, 'getContent') === true;

	}//end isCopyable()

	/**
	 * Write a file, overwriting it when it already exists; a file that cannot
	 * be written is noted as skipped.
	 *
	 * @param object $folder The target folder
	 * @param array{name: string, content: string} $file The file name and content
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return bool True when the file was written
	 */
	private function writeFileDefensively(object $folder, array $file): bool {
		try {
			$folder->newFile($file['name'], $file['content']);
			return true;
		} catch (\Throwable) {
			// Fall through: the file may already exist (idempotent re-assembly).
		}

		try {
			$folder->get($file['name'])->putContent($file['content']);
			return true;
		} catch (\Throwable $e) {
			$this->skipped[] = $file['name'] . ' (' . $e->getMessage() . ')';
			return false;
		}

	}//end writeFileDefensively()

	/**
	 * The folder name of an agenda item: its number and a path-safe title.
	 *
	 * @param int $number The item's position
	 * @param array<string, mixed> $item Agenda item payload
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return string
	 */
	private function itemFolderName(int $number, array $item): string {
		$title = (string)($item['title'] ?? 'Agenda item');
		$clean = preg_replace('/[\/\\\\:*?"<>|]+/', '-', $title) ?? '';
		$clean = preg_replace('/[[:cntrl:]]+/', '', $clean) ?? '';
		$clean = trim($clean, ' .-');
		if ($clean === '') {
			$clean = 'Agenda item';
		}

		return sprintf('%02d - %s', $number, $clean);
	}//end itemFolderName()
}//end class
