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

use OCA\Decidiq\Exception\MissingObjectException;
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
	public const TYPES = AnnotationInput::TYPES;

	/**
	 * The colours.
	 */
	public const COLORS = AnnotationInput::COLORS;

	/**
	 * The export columns, in order.
	 */
	public const EXPORT_COLUMNS = ['document', 'page', 'type', 'text', 'visibility', 'version', 'date'];

	/**
	 * Constructor.
	 *
	 * @param AnnotationStore             $store         Reads and writes the annotations in system context
	 * @param AnnotationVisibilityService $visibility    Who sees and changes what
	 * @param DocumentVersionReader       $versions      The file's current version
	 * @param ITimeFactory                $timeFactory   Clock
	 * @param CsvCell                     $csvCell       CSV cell escaping
	 * @param AnnotationInput             $input         Checks and shapes the request body
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function __construct(
		private readonly AnnotationStore $store,
		private readonly AnnotationVisibilityService $visibility,
		private readonly DocumentVersionReader $versions,
		private readonly ITimeFactory $timeFactory,
		private readonly CsvCell $csvCell,
		private readonly AnnotationInput $input,
	) {
	}//end __construct()

	/**
	 * The annotations of a document the user may see, all versions, by page.
	 *
	 * @param int    $fileId The Nextcloud file id
	 * @param string $userId The user asking
	 *
	 * @return array<string, mixed> results (by page), fileVersion, versionLabel, name and path of the
	 *                              current version, and canShareWithFaction
	 *
	 * @throws MissingObjectException When the user cannot read the file.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
	 */
	public function listForDocument(int $fileId, string $userId): array {
		$current = $this->versions->current(userId: $userId, fileId: $fileId);

		$results = [];
		foreach ($this->store->rows(filters: ['fileId' => $fileId]) as $annotation) {
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
			'name'                => $current['name'],
			'path'                => $current['path'],
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
	 * @throws \OCA\Decidiq\Exception\AnnotationRefusedException When the content is refused.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 */
	public function create(int $fileId, array $data, string $userId): array {
		$current = $this->versions->current(userId: $userId, fileId: $fileId);

		$annotation = $this->input->anchor(data: $data);
		$annotation += $this->input->content(data: $data, stored: []);
		$annotation += $this->input->context(data: $data);

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

		$saved = $this->store->save(annotation: $annotation, uuid: null);
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
	 * @throws \OCA\Decidiq\Exception\AnnotationRefusedException When the content is refused.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function update(string $id, array $data, string $userId): array {
		$stored = $this->visibleOrMissing(id: $id, userId: $userId);
		$this->visibility->assertAuthor(annotation: $stored, userId: $userId);

		$annotation = $this->input->content(data: $data, stored: $stored) + $stored;
		if (array_key_exists('visibility', $data) === true) {
			$annotation['visibility'] = (string)$data['visibility'];
		}

		$this->visibility->assertMayShare(
			visibility: (string)($annotation['visibility'] ?? 'private'),
			userId: $userId,
			meetingId: (string)($annotation['meeting'] ?? '')
		);

		unset($annotation['id'], $annotation['current']);

		return $this->store->save(annotation: $annotation, uuid: $id);
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

		$this->store->removeAll(ids: [$id]);
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

		foreach ($this->store->rows(filters: ['meeting' => $meetingId]) as $annotation) {
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
		return $this->store->removeAll(ids: array_map(static fn (array $annotation): string => (string)$annotation['id'], $this->own(userId: $userId)));
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
				$this->store->rows(filters: ['author' => $userId]),
				static fn (array $annotation): bool => (string)($annotation['author'] ?? '') === $userId && (string)($annotation['id'] ?? '') !== ''
			)
		);
	}//end own()

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
		$annotation = $this->store->find(id: $id);

		if ($annotation === null || $this->visibility->canRead(annotation: $annotation, userId: $userId) === false) {
			throw new MissingObjectException(message: 'Annotation not found.');
		}

		return $annotation;
	}//end visibleOrMissing()
}//end class
