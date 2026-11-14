<?php

/**
 * Decidiq Annotation Service
 *
 * Reads and writes annotations on meeting documents. Every read is filtered
 * by {@see AnnotationVisibilityService} and every change is the author's
 * alone, so the OpenRegister calls below run in system context: the schema
 * lets members read only their own annotations and write none, and this
 * service is the one door to the rest.
 *
 * Version binding: an annotation records the version of the file it was made
 * on, read here from the file and never from the request. A listed annotation
 * made on an earlier version is marked not current and keeps its label.
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

use OCA\Decidiq\Exception\AnnotationRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Annotation reads, writes, counts and the author's own export and removal.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
class AnnotationService {
	/**
	 * The schema slug.
	 */
	public const SCHEMA = 'annotation';

	/**
	 * The annotation types.
	 */
	public const TYPES = ['highlight', 'sticky-note', 'drawing'];

	/**
	 * The colours.
	 */
	public const COLORS = ['yellow', 'blue', 'red', 'green'];

	/**
	 * The export columns, in order.
	 */
	public const EXPORT_COLUMNS = ['document', 'page', 'type', 'text', 'visibility', 'version', 'date'];

	/**
	 * Most highlighted areas on one highlight.
	 */
	private const MAX_QUADS = 200;

	/**
	 * Most points on one drawing.
	 */
	private const MAX_PATH = 5000;

	/**
	 * Longest note.
	 */
	private const MAX_BODY = 4000;

	/**
	 * Longest quote.
	 */
	private const MAX_QUOTE = 2000;

	/**
	 * Most annotations read in one go.
	 */
	private const LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService OpenRegister object service
	 * @param AnnotationVisibilityService $visibility    Who sees and changes what
	 * @param DocumentVersionReader       $versions      The file's current version
	 * @param ITimeFactory                $timeFactory   Clock
	 * @param CsvCell                     $csvCell       CSV cell escaping
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AnnotationVisibilityService $visibility,
		private readonly DocumentVersionReader $versions,
		private readonly ITimeFactory $timeFactory,
		private readonly CsvCell $csvCell,
	) {
	}//end __construct()

	/**
	 * The annotations of a document the user may see, all versions, by page.
	 *
	 * @param int    $fileId The Nextcloud file id
	 * @param string $userId The user asking
	 *
	 * @return array{results: list<array<string, mixed>>, fileVersion: string, versionLabel: string, canShareWithFaction: bool}
	 *
	 * @throws MissingObjectException When the user cannot read the file.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
	 */
	public function listForDocument(int $fileId, string $userId): array {
		$current = $this->versions->current(userId: $userId, fileId: $fileId);

		$results = [];
		foreach ($this->rows(filters: ['fileId' => $fileId]) as $annotation) {
			if ($this->visibility->canRead(annotation: $annotation, userId: $userId) === false) {
				continue;
			}

			$annotation['current'] = ((string)($annotation['fileVersion'] ?? '') === $current['fileVersion']);
			$results[] = $annotation;
		}

		usort($results, static fn (array $a, array $b): int => ((int)($a['page'] ?? 0) <=> (int)($b['page'] ?? 0)));

		return [
			'results'             => $results,
			'fileVersion'         => $current['fileVersion'],
			'versionLabel'        => $current['versionLabel'],
			'canShareWithFaction' => $this->visibility->hasFaction(userId: $userId),
		];
	}//end listForDocument()

	/**
	 * Save a new annotation on a document the user can read.
	 *
	 * The author, the file and its version come from the session and the file,
	 * never from the request.
	 *
	 * @param int                  $fileId The Nextcloud file id
	 * @param array<string, mixed> $data   The request body
	 * @param string               $userId The author
	 *
	 * @return array<string, mixed> The stored annotation
	 *
	 * @throws MissingObjectException When the user cannot read the file.
	 * @throws AnnotationRefusedException When the content is refused.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 */
	public function create(int $fileId, array $data, string $userId): array {
		$current = $this->versions->current(userId: $userId, fileId: $fileId);

		$annotation = $this->anchor(data: $data);
		$annotation += $this->content(data: $data, stored: []);
		$annotation += $this->context(data: $data);

		$annotation['visibility'] = (string)($data['visibility'] ?? 'private');
		$this->visibility->assertMayShare(
			visibility: $annotation['visibility'],
			userId: $userId,
			meetingId: (string)($annotation['meeting'] ?? '')
		);

		$annotation['fileId'] = $fileId;
		$annotation['fileVersion'] = $current['fileVersion'];
		$annotation['versionLabel'] = $current['versionLabel'];
		$annotation['documentName'] = $current['name'];
		$annotation['author'] = $userId;
		$annotation['createdAt'] = $this->timeFactory->getDateTime()->format(DATE_ATOM);

		$saved = $this->save(annotation: $annotation, uuid: null);
		$saved['current'] = true;

		return $saved;
	}//end create()

	/**
	 * Change an annotation's note, colour or visibility. Author only.
	 *
	 * An annotation the user may not see answers as missing, so a stranger's
	 * private note cannot be detected by trying to change it.
	 *
	 * @param string               $id     The annotation
	 * @param array<string, mixed> $data   The request body
	 * @param string               $userId The user asking
	 *
	 * @return array<string, mixed> The stored annotation
	 *
	 * @throws MissingObjectException When it does not exist or is not visible.
	 * @throws \OCA\Decidiq\Exception\AccessDeniedException When the user is not the author.
	 * @throws AnnotationRefusedException When the content is refused.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function update(string $id, array $data, string $userId): array {
		$stored = $this->visibleOrMissing(id: $id, userId: $userId);
		$this->visibility->assertAuthor(annotation: $stored, userId: $userId);

		$annotation = $this->content(data: $data, stored: $stored) + $stored;
		if (array_key_exists('visibility', $data) === true) {
			$annotation['visibility'] = (string)$data['visibility'];
		}

		$this->visibility->assertMayShare(
			visibility: (string)($annotation['visibility'] ?? 'private'),
			userId: $userId,
			meetingId: (string)($annotation['meeting'] ?? '')
		);

		unset($annotation['id'], $annotation['current']);

		return $this->save(annotation: $annotation, uuid: $id);
	}//end update()

	/**
	 * Delete an annotation. Author only.
	 *
	 * @param string $id     The annotation
	 * @param string $userId The user asking
	 *
	 * @return void
	 *
	 * @throws MissingObjectException When it does not exist or is not visible.
	 * @throws \OCA\Decidiq\Exception\AccessDeniedException When the user is not the author.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function delete(string $id, string $userId): void {
		$stored = $this->visibleOrMissing(id: $id, userId: $userId);
		$this->visibility->assertAuthor(annotation: $stored, userId: $userId);

		$this->remove(id: $id);
	}//end delete()

	/**
	 * Per agenda item of a meeting, how many annotations the user may see.
	 *
	 * Counted behind the same visibility filter as the list, so a count never
	 * reveals another user's private note. Items without one are left out.
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The user asking
	 *
	 * @return array<string, int> Agenda item id => count
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows
	 */
	public function countsForMeeting(string $meetingId, string $userId): array {
		$counts = [];
		if (trim($meetingId) === '') {
			return $counts;
		}

		foreach ($this->rows(filters: ['meeting' => $meetingId]) as $annotation) {
			$item = (string)($annotation['agendaItem'] ?? '');
			if ($item === '' || $this->visibility->canRead(annotation: $annotation, userId: $userId) === false) {
				continue;
			}

			$counts[$item] = (($counts[$item] ?? 0) + 1);
		}

		return $counts;
	}//end countsForMeeting()

	/**
	 * The user's own annotations as export rows, oldest first.
	 *
	 * Only annotations the user wrote; a shared one by someone else is never
	 * included.
	 *
	 * @param string $userId The user
	 *
	 * @return list<array<string, string|int>> One row per annotation, keyed by EXPORT_COLUMNS
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
	 */
	public function exportRows(string $userId): array {
		$rows = [];
		foreach ($this->own(userId: $userId) as $annotation) {
			$document = (string)($annotation['documentName'] ?? '');
			if ($document === '') {
				$document = 'File ' . (string)($annotation['fileId'] ?? '');
			}

			$rows[] = [
				'document'   => $document,
				'page'       => (int)($annotation['page'] ?? 0),
				'type'       => (string)($annotation['annotationType'] ?? ''),
				'text'       => (string)($annotation['bodyText'] ?? ($annotation['quote'] ?? '')),
				'visibility' => (string)($annotation['visibility'] ?? 'private'),
				'version'    => (string)($annotation['versionLabel'] ?? ''),
				'date'       => (string)($annotation['createdAt'] ?? ''),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));

		return $rows;
	}//end exportRows()

	/**
	 * The user's own annotations as CSV, with a header row.
	 *
	 * @param string $userId The user
	 *
	 * @return string
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
	 */
	public function exportCsv(string $userId): string {
		$lines = [implode(',', self::EXPORT_COLUMNS)];
		foreach ($this->exportRows(userId: $userId) as $row) {
			$cells = [];
			foreach (self::EXPORT_COLUMNS as $column) {
				$cells[] = $this->csvCell->escape(cell: (string)$row[$column]);
			}

			$lines[] = implode(',', $cells);
		}

		return implode("\r\n", $lines) . "\r\n";
	}//end exportCsv()

	/**
	 * Delete every annotation the user wrote, shared ones included.
	 *
	 * @param string $userId The user
	 *
	 * @return int How many were deleted
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
	 */
	public function deleteOwn(string $userId): int {
		$deleted = 0;
		foreach ($this->own(userId: $userId) as $annotation) {
			$this->remove(id: (string)$annotation['id']);
			$deleted++;
		}

		return $deleted;
	}//end deleteOwn()

	/**
	 * The annotations the user wrote.
	 *
	 * Filtered on the author once more after the query, so a filter OpenRegister
	 * ignored can never widen the result to other people's notes.
	 *
	 * @param string $userId The user
	 *
	 * @return list<array<string, mixed>>
	 */
	private function own(string $userId): array {
		if ($userId === '') {
			return [];
		}

		return array_values(
			array_filter(
				$this->rows(filters: ['author' => $userId]),
				static fn (array $annotation): bool => (string)($annotation['author'] ?? '') === $userId && (string)($annotation['id'] ?? '') !== ''
			)
		);
	}//end own()

	/**
	 * The anchor of a new annotation: type, page and the position the type needs.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When the anchor is incomplete or off the page.
	 */
	private function anchor(array $data): array {
		$type = (string)($data['annotationType'] ?? '');
		if (in_array(needle: $type, haystack: self::TYPES, strict: true) === false) {
			throw new AnnotationRefusedException(message: 'Annotation type must be highlight, sticky-note or drawing.');
		}

		$page = $data['page'] ?? null;
		if (is_numeric($page) === false || (int)$page < 1 || (float)$page !== (float)(int)$page) {
			throw new AnnotationRefusedException(message: 'Page must be a whole number of at least 1.');
		}

		$anchor = ['annotationType' => $type, 'page' => (int)$page];

		if ($type === 'highlight') {
			$anchor['quads'] = $this->points(value: ($data['quads'] ?? null), size: 8, max: self::MAX_QUADS, label: 'quads');
			$quote = $data['quote'] ?? null;
			if (is_string($quote) === true && trim($quote) !== '') {
				$anchor['quote'] = $this->bounded(text: $quote, max: self::MAX_QUOTE, label: 'Quote');
			}
		}

		if ($type === 'sticky-note') {
			$point = $data['point'] ?? null;
			if (is_array($point) === false
				|| $this->normalised(value: ($point['x'] ?? null)) === false
				|| $this->normalised(value: ($point['y'] ?? null)) === false
			) {
				throw new AnnotationRefusedException(message: 'A sticky note needs a point with x and y between 0 and 1.');
			}

			$anchor['point'] = ['x' => (float)$point['x'], 'y' => (float)$point['y']];
		}

		if ($type === 'drawing') {
			$path = $this->points(value: ($data['path'] ?? null), size: 2, max: self::MAX_PATH, label: 'path');
			if (count($path) < 2) {
				throw new AnnotationRefusedException(message: 'A drawing needs at least two points.');
			}

			$anchor['path'] = $path;
		}

		return $anchor;
	}//end anchor()

	/**
	 * The editable content: note text and colour.
	 *
	 * @param array<string, mixed> $data   The request body
	 * @param array<string, mixed> $stored The stored annotation, empty when new
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When the text is too long or the colour unknown.
	 */
	private function content(array $data, array $stored): array {
		$content = [];
		if (array_key_exists('bodyText', $data) === true) {
			$content['bodyText'] = $this->bounded(text: (string)$data['bodyText'], max: self::MAX_BODY, label: 'Note');
		}

		$color = (string)($data['color'] ?? ($stored['color'] ?? 'yellow'));
		if (in_array(needle: $color, haystack: self::COLORS, strict: true) === false) {
			throw new AnnotationRefusedException(message: 'Colour must be yellow, blue, red or green.');
		}

		$content['color'] = $color;

		return $content;
	}//end content()

	/**
	 * The agenda item, meeting and document the annotation belongs to.
	 *
	 * A meeting not given is taken from the agenda item. The meeting must be
	 * one the author can read, so a note cannot be shared with a body the author
	 * has nothing to do with.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, string>
	 *
	 * @throws AnnotationRefusedException When a reference does not resolve.
	 */
	private function context(array $data): array {
		$context = [];
		foreach (['document', 'agendaItem', 'meeting'] as $field) {
			$value = trim((string)($data[$field] ?? ''));
			if ($value !== '') {
				$context[$field] = $value;
			}
		}

		if (isset($context['agendaItem']) === true) {
			$item = $this->readable(schema: 'agenda-item', id: $context['agendaItem']);
			if ($item === null) {
				throw new AnnotationRefusedException(message: 'The agenda item was not found.');
			}

			$context['meeting'] = ($context['meeting'] ?? trim((string)($item['meeting'] ?? '')));
			if ($context['meeting'] === '') {
				unset($context['meeting']);
			}
		}

		if (isset($context['meeting']) === true && $this->readable(schema: 'meeting', id: $context['meeting']) === null) {
			throw new AnnotationRefusedException(message: 'The meeting was not found.');
		}

		return $context;
	}//end context()

	/**
	 * Read an object as the acting user, null when it resolves to nothing.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The object id
	 *
	 * @return array<string, mixed>|null
	 */
	private function readable(string $schema, string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->getObject();
	}//end readable()

	/**
	 * A list of fixed-size point arrays with every value between 0 and 1.
	 *
	 * @param mixed  $value The request value
	 * @param int    $size  Numbers per entry
	 * @param int    $max   Most entries
	 * @param string $label The field name, for the message
	 *
	 * @return list<list<float>>
	 *
	 * @throws AnnotationRefusedException When the shape or a value is wrong.
	 */
	private function points(mixed $value, int $size, int $max, string $label): array {
		if (is_array($value) === false || $value === [] || count($value) > $max) {
			throw new AnnotationRefusedException(message: ucfirst($label) . ' must hold between 1 and ' . $max . ' entries.');
		}

		$points = [];
		foreach ($value as $entry) {
			if (is_array($entry) === false || count($entry) !== $size) {
				throw new AnnotationRefusedException(message: 'Each entry of ' . $label . ' must hold ' . $size . ' numbers.');
			}

			$numbers = [];
			foreach ($entry as $number) {
				if ($this->normalised(value: $number) === false) {
					throw new AnnotationRefusedException(message: 'Positions in ' . $label . ' must lie between 0 and 1.');
				}

				$numbers[] = (float)$number;
			}

			$points[] = $numbers;
		}

		return $points;
	}//end points()

	/**
	 * Whether a value is a number between 0 and 1.
	 *
	 * @param mixed $value The value
	 *
	 * @return bool
	 */
	private function normalised(mixed $value): bool {
		return (is_int($value) === true || is_float($value) === true) && $value >= 0 && $value <= 1;
	}//end normalised()

	/**
	 * Text no longer than a maximum.
	 *
	 * @param string $text  The text
	 * @param int    $max   Most characters
	 * @param string $label The field name, for the message
	 *
	 * @return string
	 *
	 * @throws AnnotationRefusedException When it is longer.
	 */
	private function bounded(string $text, int $max, string $label): string {
		if (mb_strlen($text) > $max) {
			throw new AnnotationRefusedException(message: $label . ' can be at most ' . $max . ' characters.');
		}

		return $text;
	}//end bounded()

	/**
	 * The annotation when the user may see it.
	 *
	 * @param string $id     The annotation
	 * @param string $userId The user asking
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MissingObjectException When it does not exist or is not visible.
	 */
	private function visibleOrMissing(string $id, string $userId): array {
		$annotation = null;
		if (trim($id) !== '') {
			try {
				$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: self::SCHEMA, _rbac: false, _multitenancy: false);
			} catch (DoesNotExistException) {
				$entity = null;
			}

			if ($entity !== null) {
				$annotation = ['id' => (string)$entity->getUuid()] + $entity->getObject();
			}
		}

		if ($annotation === null || $this->visibility->canRead(annotation: $annotation, userId: $userId) === false) {
			throw new MissingObjectException(message: 'Annotation not found.');
		}

		return $annotation;
	}//end visibleOrMissing()

	/**
	 * Read annotations in system context; callers filter what the user sees.
	 *
	 * @param array<string, mixed> $filters Property filters
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => (['register' => 'decidiq', 'schema' => self::SCHEMA] + $filters), 'limit' => self::LIMIT],
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
	 */
	private function save(array $annotation, ?string $uuid): array {
		$saved = $this->objectService->saveObject(
			object: $annotation,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => (string)$saved->getUuid()] + $saved->getObject();
	}//end save()

	/**
	 * Delete in system context, after the checks above.
	 *
	 * @param string $id The annotation
	 *
	 * @return void
	 */
	private function remove(string $id): void {
		$this->objectService->deleteObject(
			uuid: $id,
			register: 'decidiq',
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end remove()
}//end class
