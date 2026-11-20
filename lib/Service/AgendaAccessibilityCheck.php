<?php

/**
 * Runs the document accessibility gate on the papers an agenda publishes.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Support\OpenRegisterFiles;

/**
 * Collects the files on the meeting and on each of its agenda items and hands
 * them to the gate. The gate throws when it holds the publication back, and
 * returns the recorded override when the publisher went past it.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class AgendaAccessibilityCheck {

	/**
	 * Agenda item rules, used to read an item's id.
	 *
	 * @var AgendaRules
	 */
	private readonly AgendaRules $rules;

	/**
	 * Constructor.
	 *
	 * @param DocumentPublicationGate $documentGate The document accessibility gate
	 * @param OpenRegisterFiles|null  $files        OpenRegister's files, read for the gate
	 */
	public function __construct(
		private readonly DocumentPublicationGate $documentGate,
		private readonly ?OpenRegisterFiles $files=null,
	) {
		$this->rules = new AgendaRules();
	}//end __construct()

	/**
	 * Check an agenda's papers; the override to keep on the meeting, or null when there is none.
	 *
	 * @param array<string, mixed> $publication meetingId, meeting and items, as publishAgenda() collects them
	 * @param array<string, mixed> $consent     `acknowledged` and `overrideReason` of the publish request
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function overrideFor(array $publication, array $consent): ?array {
		return $this->documentGate->check(documents: $this->agendaDocuments(publication: $publication), consent: $consent);
	}//end overrideFor()

	/**
	 * The files on a meeting and its agenda items, as documents for the accessibility gate.
	 *
	 * Without OpenRegister no papers are published with the agenda, so there are none to check.
	 *
	 * @param array<string, mixed> $publication meetingId, meeting and items, as publishAgenda() collects them
	 *
	 * @return list<array{fileId: int, fileName: string, sourceObject: string, sourceSchema: string, governanceBody: string|null}>
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	private function agendaDocuments(array $publication): array {
		if ($this->files === null || $this->files->installed() === false) {
			return [];
		}

		$body = ((string)($publication['meeting']['governanceBody'] ?? '') ?: null);

		$documents = [];
		foreach ($this->documentSources(meetingId: $publication['meetingId'], items: $publication['items']) as [$sourceId, $schema]) {
			foreach ((array)$this->files->fileService()->getFiles($sourceId) as $node) {
				$documents[] = [
					'fileId' => (int)$node->getId(),
					'fileName' => (string)$node->getName(),
					'sourceObject' => $sourceId,
					'sourceSchema' => $schema,
					'governanceBody' => $body,
				];
			}
		}

		return $documents;
	}//end agendaDocuments()

	/**
	 * The meeting and each of its agenda items, as `[id, schema]` pairs whose files are published.
	 *
	 * @param string   $meetingId UUID of the Meeting
	 * @param iterable $items     Its agenda items
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function documentSources(string $meetingId, iterable $items): array {
		$sources = [[$meetingId, 'meeting']];
		foreach ($items as $item) {
			$itemId = $this->itemId(item: $item);
			if ($itemId !== '') {
				$sources[] = [$itemId, 'agenda-item'];
			}
		}

		return $sources;
	}//end documentSources()

	/**
	 * An agenda item's id, from its data or else its entity uuid.
	 *
	 * @param mixed $item The agenda item.
	 *
	 * @return string The id, or '' when it has none.
	 */
	private function itemId(mixed $item): string {
		$itemId = (string)($this->rules->toArray(item: $item)['id'] ?? '');
		if ($itemId === '' && is_object($item) === true && method_exists($item, 'getUuid') === true) {
			$itemId = (string)$item->getUuid();
		}

		return $itemId;
	}//end itemId()

}//end class
