<?php

/**
 * Decidiq ExportBundleService
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\BackgroundJob\ExportBundleNoticeJob;
use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Exports a selection of motions or decisions, or every one matching the
 * list's filter, with their attachments: one ZIP of the documents as they
 * are, or one bookmarked PDF merged by filinq.
 *
 * Every decision is read as the person who asked (OpenRegister applies its
 * read rules), and filinq reads every attachment as that person too, so an
 * export never holds a document its requester could not open.
 */
class ExportBundleService {
	/**
	 * The most decisions one export may hold.
	 */
	public const MAX_DECISIONS = 500;

	/**
	 * Above this many estimated pages filinq merges in the background.
	 */
	public const QUEUE_PAGES = 150;

	/**
	 * The folder in the requester's Files the exports land in.
	 */
	public const FOLDER = 'Decidiq exports';

	/**
	 * Where the rendered text pages wait for the merge.
	 */
	private const WORK_FOLDER = '.work';

	/**
	 * The lists that may be exported, by the name the file carries.
	 */
	private const LISTS = ['Motions', 'Decisions'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister objects, read as the caller.
	 * @param ContainerInterface     $container     Resolves OpenRegister's FileService and filinq's merge.
	 * @param FilinqPdf              $pdf           Renders a decision's text page.
	 * @param IRootFolder            $rootFolder    The requester's Files.
	 * @param IUserSession           $userSession   The requester.
	 * @param ITempManager           $tempManager   Local scratch space for the ZIP.
	 * @param IJobList               $jobList       Schedules the ready notice for a queued PDF.
	 * @param ITimeFactory           $time          The date in the file name.
	 * @param LoggerInterface        $logger        Diagnostics.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly FilinqPdf $pdf,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ITempManager $tempManager,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether one PDF can be made here: filinq's merge service is installed.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @return bool
	 */
	public function pdfAvailable(): bool {
		return FleetAppId::getService($this->container, 'filinq', 'Service\DocumentMergeService') !== null;
	}//end pdfAvailable()

	/**
	 * Build an export.
	 *
	 * @param string              $format `pdf` or `zip`.
	 * @param string              $list   `Motions` or `Decisions`, the file's name.
	 * @param list<string>        $ids    The selected decisions; empty means "all matching the filter".
	 * @param array<string,mixed> $filter The list's filter.
	 * @param string              $search The list's search text.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @throws ExportBundleException When the export is refused; the status says why.
	 *
	 * @return array{status:string,format:string,name:string,path:string,fileId:int|null}
	 */
	public function export(string $format, string $list, array $ids, array $filter, string $search): array {
		if (in_array($format, ['pdf', 'zip'], true) === false) {
			throw new ExportBundleException('Choose one PDF or a ZIP.', Http::STATUS_BAD_REQUEST);
		}

		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			throw new ExportBundleException('Sign in to export.', Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($list, self::LISTS, true) === false) {
			$list = 'Decisions';
		}

		$merger = null;
		if ($format === 'pdf') {
			$merger = FleetAppId::getService($this->container, 'filinq', 'Service\DocumentMergeService');
			if ($merger === null) {
				throw new ExportBundleException(
					'One PDF needs the filinq app, which is not installed. Export a ZIP instead.',
					Http::STATUS_SERVICE_UNAVAILABLE
				);
			}
		}

		$decisions = $this->decisions(ids: $ids, filter: $filter, search: $search);
		if ($decisions === []) {
			throw new ExportBundleException('Nothing to export: select rows or widen the filter.', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if (count($decisions) > self::MAX_DECISIONS) {
			throw new ExportBundleException(
				sprintf('This export would hold more than %d decisions. Narrow the filter and try again.', self::MAX_DECISIONS),
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$folder = $this->exportsFolder(uid: $uid);
		$name   = $list . ' ' . $this->time->getDateTime()->format('Y-m-d');

		if ($merger === null) {
			return $this->zip(folder: $folder, name: $name . '.zip', decisions: $decisions);
		}

		return $this->mergedPdf(merger: $merger, folder: $folder, name: $name . '.pdf', decisions: $decisions, uid: $uid);
	}//end export()

	/**
	 * The decisions to export, in list order, read as the caller.
	 *
	 * Asks for one more than the limit, so "too many" is known without
	 * reading the whole register.
	 *
	 * @param list<string>        $ids    The selection, or empty.
	 * @param array<string,mixed> $filter The list's filter.
	 * @param string              $search The list's search text.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return list<array<string,mixed>>
	 */
	private function decisions(array $ids, array $filter, string $search): array {
		$filters = ['register' => 'decidiq', 'schema' => 'decision'];
		foreach ($filter as $key => $value) {
			$key = (string)$key;
			if ($key === '' || $key[0] === '_' || $key === 'register' || $key === 'schema' || $key === 'action') {
				continue;
			}

			if (is_scalar($value) === true || is_array($value) === true) {
				$filters[$key] = $value;
			}
		}

		$config = ['filters' => $filters, 'limit' => (self::MAX_DECISIONS + 1)];
		$ids    = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== ''));
		if ($ids !== []) {
			$config = ['filters' => ['register' => 'decidiq', 'schema' => 'decision'], 'ids' => $ids, 'limit' => (self::MAX_DECISIONS + 1)];
		} else if (trim($search) !== '') {
			$config['search'] = trim($search);
		}

		$rows = $this->objectService->findAll(config: $config);

		$decisions = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$decisions[] = $data;
			}
		}

		if ($ids === []) {
			return $decisions;
		}

		// A selection keeps the order the clerk saw.
		$position = array_flip($ids);
		usort(
			$decisions,
			static fn (array $a, array $b): int => ($position[self::idOf(decision: $a)] ?? PHP_INT_MAX) <=> ($position[self::idOf(decision: $b)] ?? PHP_INT_MAX)
		);

		return $decisions;
	}//end decisions()

	/**
	 * Write the ZIP: one folder per decision with its text and its attachments.
	 *
	 * @param Folder                    $folder    The exports folder.
	 * @param string                    $name      The file name.
	 * @param list<array<string,mixed>> $decisions The decisions.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @throws ExportBundleException When the archive cannot be written.
	 *
	 * @return array{status:string,format:string,name:string,path:string,fileId:int|null}
	 */
	private function zip(Folder $folder, string $name, array $decisions): array {
		$path = (string)$this->tempManager->getTemporaryFile('.zip');
		$zip  = new ZipArchive();
		if ($path === '' || $zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			throw new ExportBundleException('The export could not be written. Try again.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$used = [];
		foreach ($decisions as $index => $decision) {
			$dir = $this->unique(name: sprintf('%03d %s', ($index + 1), self::safeName(name: self::titleOf(decision: $decision))), used: $used);
			$zip->addFromString($dir . '/decision.html', $this->html(decision: $decision));
			$inDir = [];
			foreach ($this->attachments(decision: $decision) as $file) {
				$zip->addFromString($dir . '/' . $this->unique(name: self::safeName(name: (string)$file->getName()), used: $inDir), (string)$file->getContent());
			}
		}

		$zip->close();

		$stream = fopen($path, 'r');
		if ($stream === false) {
			throw new ExportBundleException('The export could not be written. Try again.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$name = $folder->getNonExistingName($name);
		$file = $folder->newFile($name, $stream);
		if (is_resource($stream) === true) {
			fclose($stream);
		}

		return ['status' => 'done', 'format' => 'zip', 'name' => $name, 'path' => self::FOLDER . '/' . $name, 'fileId' => $file->getId()];
	}//end zip()

	/**
	 * Hand the text pages and attachments to filinq, in list order.
	 *
	 * @param object                    $merger    filinq's DocumentMergeService.
	 * @param Folder                    $folder    The exports folder.
	 * @param string                    $name      The file name.
	 * @param list<array<string,mixed>> $decisions The decisions.
	 * @param string                    $uid       The requester.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @throws ExportBundleException When filinq refuses or a text page cannot be rendered.
	 *
	 * @return array{status:string,format:string,name:string,path:string,fileId:int|null}
	 */
	private function mergedPdf(object $merger, Folder $folder, string $name, array $decisions, string $uid): array {
		$work   = $this->workFolder(folder: $folder);
		$inputs = [];
		$pages  = [];
		try {
			foreach ($decisions as $index => $decision) {
				$title = self::titleOf(decision: $decision);
				$bytes = $this->pdf->fromHtml(html: $this->html(decision: $decision), title: $title, context: 'decision export');
				if ($bytes === null) {
					throw new ExportBundleException(
						'The text pages could not be made into PDF. Export a ZIP instead.',
						Http::STATUS_SERVICE_UNAVAILABLE
					);
				}

				$page    = $work->newFile($work->getNonExistingName(sprintf('%s-%03d.pdf', md5($name), ($index + 1))), $bytes);
				$pages[] = (int)$page->getId();
				$inputs[] = ['fileId' => (int)$page->getId(), 'label' => $title, 'size' => strlen($bytes)];
				foreach ($this->attachments(decision: $decision) as $file) {
					$inputs[] = ['fileId' => (int)$file->getId(), 'label' => (string)$file->getName(), 'size' => (int)$file->getSize()];
				}
			}

			$options = ['bookmarks' => true, 'targetFolder' => self::FOLDER, 'name' => $name];
			if ($merger->shouldQueue($inputs, self::QUEUE_PAGES) === true) {
				$job = $merger->queue($inputs, $options, ['app' => 'decidiq', 'export' => $name]);
				$this->jobList->add(
					ExportBundleNoticeJob::class,
					['mergeJob' => (string)($job['uuid'] ?? $job['id'] ?? ''), 'uid' => $uid, 'name' => $name, 'pages' => $pages, 'attempt' => 0]
				);

				return ['status' => 'queued', 'format' => 'pdf', 'name' => $name, 'path' => self::FOLDER . '/' . $name, 'fileId' => null];
			}

			$job = $merger->merge($inputs, $options, ['app' => 'decidiq', 'export' => $name]);
		} catch (ExportBundleException $e) {
			$this->removePages(uid: $uid, pages: $pages);
			throw $e;
		} catch (Throwable $e) {
			$this->removePages(uid: $uid, pages: $pages);
			$status = Http::STATUS_BAD_GATEWAY;
			if (method_exists($e, 'getStatus') === true) {
				$status = (int)$e->getStatus();
			}

			// filinq names the file it refused; that sentence is the answer.
			throw new ExportBundleException($e->getMessage(), $status, $e);
		}//end try

		$this->removePages(uid: $uid, pages: $pages);
		if (($job['status'] ?? '') !== 'done') {
			throw new ExportBundleException(
				'The PDF could not be made: ' . (string)($job['error'] ?? $job['reason'] ?? 'filinq did not finish the merge.'),
				Http::STATUS_BAD_GATEWAY
			);
		}

		return ['status' => 'done', 'format' => 'pdf', 'name' => $name, 'path' => self::FOLDER . '/' . $name, 'fileId' => (int)($job['resultFileId'] ?? 0)];
	}//end mergedPdf()

	/**
	 * Remove the rendered text pages once the merge no longer needs them.
	 *
	 * @param string    $uid   The requester.
	 * @param list<int> $pages The page file ids.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 *
	 * @return void
	 */
	public function removePages(string $uid, array $pages): void {
		if ($pages === []) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			foreach ($pages as $id) {
				foreach ($userFolder->getById((int)$id) as $node) {
					$node->delete();
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq export: a text page could not be removed', ['error' => $e->getMessage()]);
		}
	}//end removePages()

	/**
	 * The files attached to a decision, readable ones only.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return list<\OCP\Files\File>
	 */
	private function attachments(array $decision): array {
		$id = self::idOf(decision: $decision);
		if ($id === '') {
			return [];
		}

		$fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
		$nodes       = $fileService->getFiles($id);

		return array_values(
			array_filter(
				(array)$nodes,
				static fn (mixed $node): bool => $node instanceof \OCP\Files\File
			)
		);
	}//end attachments()

	/**
	 * The decision's text page.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @return string HTML.
	 */
	private function html(array $decision): string {
		$esc   = static fn (mixed $v): string => htmlspecialchars((string)$v, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
		$rows  = '';
		$facts = [
			'Number' => ($decision['resolutionNumber'] ?? $decision['externalReference'] ?? ''),
			'Type' => ($decision['motionType'] ?? $decision['decisionType'] ?? ''),
			'Proposer' => ($decision['proposer'] ?? ''),
			'Submitted' => ($decision['submittedAt'] ?? ''),
			'Decision date' => ($decision['decisionDate'] ?? ''),
			'Outcome' => ($decision['outcome'] ?? ''),
		];
		foreach ($facts as $label => $value) {
			if (is_scalar($value) === true && (string)$value !== '') {
				$rows .= '<tr><th>' . $esc($label) . '</th><td>' . $esc($value) . '</td></tr>';
			}
		}

		$text = (string)($decision['text'] ?? $decision['fullText'] ?? $decision['proposedText'] ?? '');

		return '<!doctype html><html><head><meta charset="utf-8"><title>' . $esc(self::titleOf(decision: $decision)) . '</title></head><body>'
			. '<h1>' . $esc(self::titleOf(decision: $decision)) . '</h1>'
			. '<table>' . $rows . '</table>'
			. '<div>' . nl2br($esc($text)) . '</div>'
			. '</body></html>';
	}//end html()

	/**
	 * The exports folder in the requester's Files, created when missing.
	 *
	 * @param string $uid The requester.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return Folder
	 */
	private function exportsFolder(string $uid): Folder {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		if ($userFolder->nodeExists(self::FOLDER) === false) {
			return $userFolder->newFolder(self::FOLDER);
		}

		$folder = $userFolder->get(self::FOLDER);
		if (($folder instanceof Folder) === false) {
			throw new ExportBundleException('"' . self::FOLDER . '" in your Files is not a folder. Rename it and try again.', Http::STATUS_CONFLICT);
		}

		return $folder;
	}//end exportsFolder()

	/**
	 * The folder the text pages wait in.
	 *
	 * @param Folder $folder The exports folder.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @return Folder
	 */
	private function workFolder(Folder $folder): Folder {
		if ($folder->nodeExists(self::WORK_FOLDER) === false) {
			return $folder->newFolder(self::WORK_FOLDER);
		}

		$work = $folder->get(self::WORK_FOLDER);
		if (($work instanceof Folder) === false) {
			throw new ExportBundleException('The export could not be written. Try again.', Http::STATUS_CONFLICT);
		}

		return $work;
	}//end workFolder()

	/**
	 * A name not used yet in this archive folder.
	 *
	 * @param string             $name The wanted name.
	 * @param array<string,bool> $used Names taken so far (updated).
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return string
	 */
	private function unique(string $name, array &$used): string {
		$candidate = $name;
		$counter   = 2;
		while (isset($used[strtolower($candidate)]) === true) {
			$dot       = strrpos($name, '.');
			$candidate = $name . ' (' . $counter . ')';
			if ($dot !== false && $dot > 0) {
				$candidate = substr($name, 0, $dot) . ' (' . $counter . ')' . substr($name, $dot);
			}

			$counter++;
		}

		$used[strtolower($candidate)] = true;
		return $candidate;
	}//end unique()

	/**
	 * A name that is safe as a path segment.
	 *
	 * @param string $name The name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 */
	private static function safeName(string $name): string {
		$name = trim((string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', ' ', $name));
		$name = trim($name, '. ');
		if ($name === '') {
			return 'untitled';
		}

		return mb_substr($name, 0, 120);
	}//end safeName()

	/**
	 * A decision's title.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	private static function titleOf(array $decision): string {
		$title = trim((string)($decision['title'] ?? ''));
		if ($title === '') {
			return 'Untitled decision';
		}

		return $title;
	}//end titleOf()

	/**
	 * A decision's id.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 */
	private static function idOf(array $decision): string {
		return (string)($decision['id'] ?? $decision['@self']['id'] ?? $decision['uuid'] ?? '');
	}//end idOf()
}//end class
