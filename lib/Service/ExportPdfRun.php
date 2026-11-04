<?php

/**
 * Decidiq ExportPdfRun
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * One merged-PDF export: the inputs handed to filinq in list order, and the
 * rendered text pages that wait in the work folder until the merge is done.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */
final class ExportPdfRun {

	/**
	 * The merge inputs, in order.
	 *
	 * @var list<array{fileId:int,label:string,size:int}>
	 */
	private array $inputs = [];

	/**
	 * The file ids of the rendered text pages.
	 *
	 * @var list<int>
	 */
	private array $pages = [];

	/**
	 * Create a run.
	 *
	 * @param Folder                         $work   Where the text pages wait.
	 * @param array{name:string,uid:string} $export The export file name and the requester.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly Folder $work,
		private readonly array $export,
	) {
	}//end __construct()

	/**
	 * Store a rendered text page and add it to the inputs.
	 *
	 * @param string $bytes The PDF bytes.
	 * @param string $title The decision title, the page's bookmark.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function addPage(string $bytes, string $title): void {
		$fileName = sprintf('%s-%03d.pdf', md5($this->export['name']), (count($this->pages) + 1));
		$page     = $this->work->newFile($this->work->getNonExistingName($fileName), $bytes);
		$this->pages[]  = (int)$page->getId();
		$this->inputs[] = ['fileId' => (int)$page->getId(), 'label' => $title, 'size' => strlen($bytes)];
	}//end addPage()

	/**
	 * Add an attachment to the inputs.
	 *
	 * @param File $file The attachment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function addAttachment(File $file): void {
		$this->inputs[] = ['fileId' => (int)$file->getId(), 'label' => (string)$file->getName(), 'size' => (int)$file->getSize()];
	}//end addAttachment()

	/**
	 * The merge inputs, in order.
	 *
	 * @return list<array{fileId:int,label:string,size:int}>
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function inputs(): array {
		return $this->inputs;
	}//end inputs()

	/**
	 * The file ids of the rendered text pages.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 */
	public function pages(): array {
		return $this->pages;
	}//end pages()

	/**
	 * The merge options: bookmarks, and the file in the exports folder.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function options(): array {
		return ['bookmarks' => true, 'targetFolder' => ExportBundleService::FOLDER, 'name' => $this->export['name']];
	}//end options()

	/**
	 * The merge metadata.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function meta(): array {
		return ['app' => 'decidiq', 'export' => $this->export['name']];
	}//end meta()

	/**
	 * The argument of the ready notice job for a queued merge.
	 *
	 * @param mixed $job What filinq's queue answered.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 */
	public function notice(mixed $job): array {
		return [
			'mergeJob' => (string)($job['uuid'] ?? $job['id'] ?? ''),
			'uid' => $this->export['uid'],
			'name' => $this->export['name'],
			'pages' => $this->pages,
			'attempt' => 0,
		];
	}//end notice()
}//end class
