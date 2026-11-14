<?php

/**
 * Decidiq PlannedAgendaReportService
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
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\PlannedAgendaReportException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/**
 * Downloads the long-term agenda, as its filters select it, as a formatted
 * report: a PDF rendered through filinq, saved in the requester's Files.
 *
 * Every row, and every name the report prints, is read through OpenRegister
 * as the person who asked, so the report never holds a row, a topic or a
 * person the list would hide from them. Without filinq the HTML the PDF is
 * made from is saved instead, with a note saying so (ADR-075).
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportService {
	/**
	 * The most items one report may hold.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * The folder in the requester's Files the reports land in.
	 */
	public const FOLDER = 'Decidiq/Reports';

	/**
	 * What the page says when filinq is absent.
	 */
	public const HTML_NOTE = 'filinq is not installed, so the report was saved as HTML';

	/**
	 * The register and schema the long-term agenda lives in.
	 */
	private const REGISTER = 'decidiq';
	private const SCHEMA   = 'planned-agenda-item';

	/**
	 * The schema each reference resolves in, and the field that names it.
	 */
	private const REFERENCES = [
		'owner'          => ['person', 'name'],
		'author'         => ['person', 'name'],
		'governanceBody' => ['governance-body', 'name'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister objects, read as the caller.
	 * @param PlannedAgendaReportRenderer $renderer Groups the rows and writes the HTML.
	 * @param FilinqPdf              $pdf           Renders HTML to PDF, or answers null.
	 * @param IRootFolder            $rootFolder    The requester's Files.
	 * @param IUserSession           $userSession   The requester.
	 * @param ITimeFactory           $time          The report's date.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly PlannedAgendaReportRenderer $renderer,
		private readonly FilinqPdf $pdf,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Render the report for the list's active filters and save it.
	 *
	 * @param array<string,mixed> $filter The list's filter, as the page keeps it.
	 * @param string              $search The list's search text.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @throws PlannedAgendaReportException 401 signed out, 422 nothing or too much matches, 409 the folder is a file.
	 *
	 * @return array{format:string,name:string,path:string,fileId:int|null,count:int,note:string|null}
	 */
	public function render(array $filter, string $search=''): array {
		$uid     = $this->requester();
		$filters = self::listFilters(filter: $filter);
		$rows    = $this->rows(filters: $filters, search: $search);

		$names  = $this->names(rows: $rows, filters: $filters);
		$topics = $this->topicsOutside(rows: $rows);
		$now    = $this->time->getDateTime();

		$html = $this->renderer->html(
			groups: $this->renderer->group(rows: $rows, topics: $topics),
			filters: $this->renderer->describeFilters(filters: $filters, names: ($names + $topics)),
			date: $now->format('j F Y'),
			names: $names
		);

		$name   = PlannedAgendaReportRenderer::TITLE . ' ' . $now->format('Y-m-d');
		$folder = $this->reportsFolder(uid: $uid);
		$bytes  = $this->pdf->fromHtml(html: $html, title: $name, context: 'the long-term agenda report');

		if ($bytes === null) {
			return $this->save(folder: $folder, name: $name . '.html', content: $html, format: 'html', count: count($rows), note: self::HTML_NOTE);
		}

		return $this->save(folder: $folder, name: $name . '.pdf', content: $bytes, format: 'pdf', count: count($rows), note: null);
	}//end render()

	/**
	 * The list's filter as OpenRegister filters, without the page's own keys.
	 *
	 * @param array<string,mixed> $filter The list's filter.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @return array<string,mixed>
	 */
	public static function listFilters(array $filter): array {
		$filters = [];
		foreach ($filter as $key => $value) {
			$key = (string)$key;
			if ($key === '' || $key[0] === '_' || in_array($key, ['register', 'schema', 'action', 'page', 'limit'], true) === true) {
				continue;
			}

			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$filters[$key] = $value;
		}

		return $filters;
	}//end listFilters()

	/**
	 * The signed-in requester.
	 *
	 * @throws PlannedAgendaReportException 401 when nobody is signed in.
	 *
	 * @return string The requester's uid.
	 */
	private function requester(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			throw new PlannedAgendaReportException(message: 'Sign in to download a report.', status: Http::STATUS_UNAUTHORIZED);
		}

		return $uid;
	}//end requester()

	/**
	 * The rows the filters select, read as the caller, refused when none or too many.
	 *
	 * Asks for one more than the limit, so "too many" is known without reading
	 * the whole register.
	 *
	 * @param array<string,mixed> $filters The filters.
	 * @param string              $search  The search text.
	 *
	 * @throws PlannedAgendaReportException 422 when nothing or more than the limit matches.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(array $filters, string $search): array {
		$config = [
			'filters' => (['register' => self::REGISTER, 'schema' => self::SCHEMA] + $filters),
			'limit'   => (self::MAX_ITEMS + 1),
		];
		if (trim($search) !== '') {
			$config['search'] = trim($search);
		}

		$rows = self::rowsAsArrays(rows: $this->read(config: $config));
		if ($rows === []) {
			throw new PlannedAgendaReportException(message: 'No items match these filters', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if (count($rows) > self::MAX_ITEMS) {
			throw new PlannedAgendaReportException(
				message: sprintf('This report would hold more than %d items. Narrow the filters and try again.', self::MAX_ITEMS),
				status: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $rows;
	}//end rows()

	/**
	 * The names of the people and bodies the rows and the filters refer to.
	 *
	 * @param list<array<string,mixed>> $rows    The rows.
	 * @param array<string,mixed>       $filters The filters.
	 *
	 * @return array<string,string> Names by id; a reference the caller may not read is absent.
	 */
	private function names(array $rows, array $filters): array {
		$idsBySchema = [];
		foreach (self::REFERENCES as $field => [$schema]) {
			$values = array_column($rows, $field);
			$values = array_merge($values, (array)($filters[$field] ?? []));
			$idsBySchema[$schema] = array_merge(($idsBySchema[$schema] ?? []), $values);
		}

		$names = [];
		foreach ($idsBySchema as $schema => $ids) {
			$names += $this->namesIn(schema: $schema, ids: $ids, field: self::nameField(schema: $schema));
		}

		return $names;
	}//end names()

	/**
	 * Topic subjects for tracks whose topic is not among the rows.
	 *
	 * @param list<array<string,mixed>> $rows The rows.
	 *
	 * @return array<string,string> Subjects by id; a topic the caller may not read is absent.
	 */
	private function topicsOutside(array $rows): array {
		$present = array_flip(array_map(fn (array $row): string => $this->renderer->idOf(row: $row), $rows));
		$missing = array_filter(
			array_column($rows, 'parentItem'),
			static fn (mixed $id): bool => is_string($id) === true && $id !== '' && isset($present[$id]) === false
		);

		return $this->namesIn(schema: self::SCHEMA, ids: $missing, field: 'subject');
	}//end topicsOutside()

	/**
	 * The field that names an object of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return string
	 */
	private static function nameField(string $schema): string {
		foreach (self::REFERENCES as [$refSchema, $field]) {
			if ($refSchema === $schema) {
				return $field;
			}
		}

		return 'name';
	}//end nameField()

	/**
	 * The names of the given objects of one schema, read as the caller.
	 *
	 * @param string      $schema The schema.
	 * @param list<mixed> $ids    The references, possibly repeated or empty.
	 * @param string      $field  The field that names them.
	 *
	 * @return array<string,string>
	 */
	private function namesIn(string $schema, array $ids, string $field): array {
		$ids = array_values(
			array_unique(
				array_filter(
					$ids,
					static fn (mixed $id): bool => is_string($id) === true && $id !== '' && $id !== PlannedAgendaReportRenderer::EMPTY_FILTER
				)
			)
		);
		if ($ids === []) {
			return [];
		}

		$config = ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => $ids, 'limit' => count($ids)];
		$rows   = self::rowsAsArrays(rows: $this->read(config: $config));
		$names = [];
		foreach ($rows as $row) {
			$id = $this->renderer->idOf(row: $row);
			if ($id !== '' && in_array($id, $ids, true) === true && is_string($row[$field] ?? null) === true) {
				$names[$id] = $row[$field];
			}
		}

		return $names;
	}//end namesIn()

	/**
	 * One read through OpenRegister, with the caller's own access.
	 *
	 * @param array<string,mixed> $config The findAll config.
	 *
	 * @return iterable<mixed>
	 */
	private function read(array $config): iterable {
		$result = $this->objectService->findAll(config: $config);

		return ($result['results'] ?? $result);
	}//end read()

	/**
	 * The rows that are, or serialise to, arrays.
	 *
	 * @param iterable<mixed> $rows The rows read.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function rowsAsArrays(iterable $rows): array {
		$arrays = [];
		foreach ($rows as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$arrays[] = $data;
			}
		}

		return $arrays;
	}//end rowsAsArrays()

	/**
	 * The reports folder in the requester's Files, created when missing.
	 *
	 * @param string $uid The requester.
	 *
	 * @throws PlannedAgendaReportException 409 when a part of the path is a file.
	 *
	 * @return Folder
	 */
	private function reportsFolder(string $uid): Folder {
		$folder = $this->rootFolder->getUserFolder($uid);
		foreach (explode('/', self::FOLDER) as $part) {
			if ($folder->nodeExists($part) === false) {
				$folder = $folder->newFolder($part);
				continue;
			}

			$node = $folder->get($part);
			if (($node instanceof Folder) === false) {
				throw new PlannedAgendaReportException(
					message: '"' . self::FOLDER . '" in your Files is not a folder. Rename the file in its way and try again.',
					status: Http::STATUS_CONFLICT
				);
			}

			$folder = $node;
		}

		return $folder;
	}//end reportsFolder()

	/**
	 * The report's file with the content written: a new one, or the one made
	 * earlier the same day, overwritten.
	 *
	 * @param Folder $folder  The reports folder.
	 * @param string $name    The file name.
	 * @param string $content The PDF bytes or the HTML.
	 *
	 * @throws PlannedAgendaReportException 409 when a folder has the report's name.
	 *
	 * @return File
	 */
	private function reportFile(Folder $folder, string $name, string $content): File {
		if ($folder->nodeExists($name) === false) {
			return $folder->newFile($name, $content);
		}

		$file = $folder->get($name);
		if (($file instanceof File) === false) {
			throw new PlannedAgendaReportException(
				message: '"' . $name . '" in your Files is a folder. Rename it and try again.',
				status: Http::STATUS_CONFLICT
			);
		}

		$file->putContent($content);

		return $file;
	}//end reportFile()

	/**
	 * Write the report, replacing one made earlier the same day.
	 *
	 * @param Folder      $folder  The reports folder.
	 * @param string      $name    The file name.
	 * @param string      $content The PDF bytes or the HTML.
	 * @param string      $format  `pdf` or `html`.
	 * @param int         $count   How many items it holds.
	 * @param string|null $note    What the page should say, or null.
	 *
	 * @return array{format:string,name:string,path:string,fileId:int|null,count:int,note:string|null}
	 */
	private function save(Folder $folder, string $name, string $content, string $format, int $count, ?string $note): array {
		$file = $this->reportFile(folder: $folder, name: $name, content: $content);

		return [
			'format' => $format,
			'name'   => $name,
			'path'   => '/' . self::FOLDER . '/' . $name,
			'fileId' => $file->getId(),
			'count'  => $count,
			'note'   => $note,
		];
	}//end save()
}//end class
