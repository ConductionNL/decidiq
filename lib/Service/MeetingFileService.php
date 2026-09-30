<?php

/**
 * Decidiq MeetingFileService
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Assembles the meeting file after the minutes are approved: the published
 * agenda, each item's documents, each decision, the decision list, the
 * minutes and the proof package. Each document carries the confidentiality
 * flag and ground of any restriction on it, its item or its decision.
 *
 * A document that cannot be produced is returned with its error rather than
 * dropped, so it becomes a failed line the griffier can send again.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */
class MeetingFileService {
	/**
	 * Minutes states at or past approval.
	 */
	private const APPROVED = ['approved', 'signed', 'published'];

	/**
	 * Restriction states that hold.
	 */
	private const ACTIVE = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param DecisionListService    $decisionList  The decision list.
	 * @param MinutesDocumentService $minutesDoc    The minutes document.
	 * @param ProofPackageService    $proofPackage  The proof package.
	 * @param MeetingFolderService   $folders       The meeting folder.
	 * @param FilinqPdf              $pdf           PDF rendering through filinq.
	 * @param ContainerInterface     $container     DI container (OpenRegister FileService, lazily).
	 * @param IL10N                  $l10n          Translations.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly DecisionListService $decisionList,
		private readonly MinutesDocumentService $minutesDoc,
		private readonly ProofPackageService $proofPackage,
		private readonly MeetingFolderService $folders,
		private readonly FilinqPdf $pdf,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The meeting's minutes, refused unless they are approved.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException 422 when the minutes are not approved.
	 *
	 * @return array<string,mixed>
	 */
	public function approvedMinutes(string $meetingId): array {
		foreach ($this->rows(schema: 'minutes', filters: ['meeting' => $meetingId]) as $minutes) {
			if (in_array((string)($minutes['lifecycle'] ?? ''), self::APPROVED, true) === true) {
				return $minutes;
			}
		}

		throw new CaseSystemException(message: 'Approve the minutes before sending the meeting file', status: 422);
	}//end approvedMinutes()

	/**
	 * Assemble the meeting file.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException 422 when the minutes are not approved, 404 when the meeting does not exist.
	 *
	 * @return array{meeting:array<string,mixed>,items:array<string,array<string,mixed>>,documents:list<array<string,mixed>>}
	 */
	public function assemble(string $meetingId): array {
		$minutes = $this->approvedMinutes(meetingId: $meetingId);
		$entity  = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting', _rbac: false, _multitenancy: false);
		if ($entity === null) {
			throw new CaseSystemException(message: 'Meeting not found', status: 404);
		}

		$meeting = ['id' => $meetingId] + (array)$entity->jsonSerialize();
		$items   = [];
		foreach ($this->rows(schema: 'agenda-item', filters: ['meeting' => $meetingId]) as $item) {
			$items[(string)$item['id']] = $item;
		}

		uasort($items, static fn (array $one, array $two): int => ((int)($one['orderNumber'] ?? 0) <=> (int)($two['orderNumber'] ?? 0)));

		$agenda      = fn (): string => $this->agenda(meeting: $meeting, items: $items);
		$list        = fn (): string => $this->decisionList->render(meetingId: $meetingId, minutes: $minutes)['content'];
		$documents   = [$this->produce(source: 'agenda', kind: 'agenda', name: $this->l10n->t('Agenda') . '.pdf', make: $agenda)];
		$documents   = array_merge($documents, $this->itemDocuments(items: $items));
		$documents   = array_merge($documents, $this->decisionDocuments(meetingId: $meetingId));
		$documents[] = $this->produce(source: 'decision-list', kind: 'decision-list', name: DecisionListService::BASE_NAME . '.pdf', make: $list);
		$minutesDoc  = fn (): string => $this->minutes(minutes: $minutes);
		$proof       = fn (): string => $this->proof(meetingId: $meetingId);
		$documents[] = $this->produce(source: 'minutes', kind: 'minutes', name: $this->l10n->t('Minutes') . '.pdf', make: $minutesDoc);
		$documents[] = $this->produce(source: 'proof-package', kind: 'proof-package', name: $this->l10n->t('Proof package') . '.json', make: $proof);

		return ['meeting' => $meeting, 'items' => $items, 'documents' => $this->markConfidential(documents: $documents)];
	}//end assemble()

	/**
	 * Every file on every agenda item.
	 *
	 * @param array<string,array<string,mixed>> $items The meeting's items.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return list<array<string,mixed>>
	 */
	private function itemDocuments(array $items): array {
		$documents = [];
		$files     = $this->container->get('OCA\OpenRegister\Service\FileService');
		foreach (array_keys($items) as $itemId) {
			foreach ((array)$files->getFiles($itemId) as $node) {
				$fileId      = (int)$node->getId();
				$content     = static fn (): string => (string)$node->getContent();
				$document    = $this->produce(source: 'file:' . $fileId, kind: 'item-document', name: (string)$node->getName(), make: $content);
				$documents[] = $document + ['agendaItem' => $itemId, 'fileId' => $fileId];
			}
		}

		return $documents;
	}//end itemDocuments()

	/**
	 * One document per decision, for the case of its agenda item.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return list<array<string,mixed>>
	 */
	private function decisionDocuments(string $meetingId): array {
		$documents = [];
		foreach ($this->decisionList->decisions(meetingId: $meetingId) as $decision) {
			$html     = '<h1>' . htmlspecialchars((string)($decision['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h1>';
			$html    .= '<div>' . htmlspecialchars((string)($decision['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
			$title    = $this->l10n->t('Decision') . ' ' . (string)($decision['title'] ?? '');
			$document = $this->produce(
				source: 'decision:' . (string)$decision['id'],
				kind: 'decision',
				name: $title . '.pdf',
				make: fn (): string => ($this->pdf->fromHtml(html: $html, title: $title, context: 'decision') ?? $html)
			);
			$documents[] = $document + ['agendaItem' => (string)($decision['agendaItem'] ?? ''), 'decision' => (string)$decision['id']];
		}

		return $documents;
	}//end decisionDocuments()

	/**
	 * The last published agenda, as a PDF (HTML without filinq).
	 *
	 * @param array<string,mixed>               $meeting The meeting.
	 * @param array<string,array<string,mixed>> $items   Its items, in order.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return string
	 */
	private function agenda(array $meeting, array $items): string {
		$versions = (array)($meeting['agendaVersions'] ?? []);
		$last     = [];
		if ($versions !== []) {
			$last = (array)end($versions);
		}

		$rows     = (array)($last['items'] ?? array_values($items));
		$esc      = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
		$html     = '<h1>' . $esc($this->l10n->t('Agenda')) . ' ' . $esc($meeting['title'] ?? '') . '</h1><ol>';
		foreach ($rows as $row) {
			$html .= '<li>' . $esc(((array)$row)['title'] ?? '') . '</li>';
		}

		$html .= '</ol>';
		return ($this->pdf->fromHtml(html: $html, title: $this->l10n->t('Agenda'), context: 'meeting file agenda') ?? $html);
	}//end agenda()

	/**
	 * The minutes document: the newest generated one, generated when there is none.
	 *
	 * @param array<string,mixed> $minutes The minutes.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException When it cannot be read.
	 *
	 * @return string
	 */
	private function minutes(array $minutes): string {
		$generated = (array)($minutes['generatedDocuments'] ?? []);
		$path      = '';
		if ($generated !== []) {
			$path = (string)(((array)end($generated))['path'] ?? '');
		}

		if ($path === '') {
			$path = (string)$this->minutesDoc->generate(minutesId: (string)$minutes['id'], format: 'pdf', displayName: 'decidiq')['path'];
		}

		return $this->read(path: $path);
	}//end minutes()

	/**
	 * The proof package, assembled now.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException When it cannot be read.
	 *
	 * @return string
	 */
	private function proof(string $meetingId): string {
		$package = $this->proofPackage->assemble(meetingId: $meetingId, generatedBy: 'decidiq');
		return $this->read(path: (string)($package['files'][0] ?? ''));
	}//end proof()

	/**
	 * Read a meeting folder file.
	 *
	 * @param string $path The path.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException When it cannot be read.
	 *
	 * @return string
	 */
	private function read(string $path): string {
		$content = $this->folders->readMeetingFile(path: $path);
		if ($content === null) {
			throw new CaseSystemException(message: 'The file ' . $path . ' could not be read', status: 503);
		}

		return $content;
	}//end read()

	/**
	 * One document of the file; a failure becomes the document's error.
	 *
	 * @param string   $source Where the document is found again.
	 * @param string   $kind   The document kind.
	 * @param string   $name   The file name.
	 * @param callable $make   Produces the content.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return array<string,mixed>
	 */
	private function produce(string $source, string $kind, string $name, callable $make): array {
		$document = ['source' => $source, 'kind' => $kind, 'name' => $name];
		try {
			$document['content'] = (string)$make();
		} catch (Throwable $e) {
			$document['error'] = $e->getMessage();
		}

		return $document;
	}//end produce()

	/**
	 * Mark every document under an active restriction, on itself, its item or its decision.
	 *
	 * @param list<array<string,mixed>> $documents The documents.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return list<array<string,mixed>>
	 */
	private function markConfidential(array $documents): array {
		$grounds = [];
		foreach ($this->rows(schema: 'confidentiality-restriction', filters: []) as $restriction) {
			if (in_array((string)($restriction['lifecycle'] ?? ''), self::ACTIVE, true) === false) {
				continue;
			}

			$ground = $this->groundName(groundId: (string)($restriction['ground'] ?? ''));
			foreach ($this->restrictedKeys(restriction: $restriction) as $key) {
				$grounds[$key] = $ground;
			}
		}

		foreach ($documents as $index => $document) {
			$keys = [
				'file:' . (string)($document['fileId'] ?? ''),
				'item:' . (string)($document['agendaItem'] ?? ''),
				'decision:' . (string)($document['decision'] ?? ''),
			];
			foreach ($keys as $key) {
				if (isset($grounds[$key]) === true) {
					$documents[$index]['confidential'] = true;
					$documents[$index]['ground']       = $grounds[$key];
					break;
				}
			}
		}

		return $documents;
	}//end markConfidential()

	/**
	 * The keys a restriction covers: its item, its decision, or its document's file.
	 *
	 * @param array<string,mixed> $restriction The restriction.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return list<string>
	 */
	private function restrictedKeys(array $restriction): array {
		$keys = [];
		if ((string)($restriction['targetAgendaItem'] ?? '') !== '') {
			$keys[] = 'item:' . (string)$restriction['targetAgendaItem'];
		}

		if ((string)($restriction['targetDecision'] ?? '') !== '') {
			$keys[] = 'decision:' . (string)$restriction['targetDecision'];
		}

		$documentId = (string)($restriction['targetDocument'] ?? '');
		if ($documentId !== '') {
			$document = $this->objectService->find(id: $documentId, register: 'decidiq', schema: 'digital-document', _rbac: false, _multitenancy: false);
			$fileId   = (string)(((array)$document?->getObject())['fileId'] ?? '');
			if ($fileId !== '') {
				$keys[] = 'file:' . $fileId;
			}
		}

		return $keys;
	}//end restrictedKeys()

	/**
	 * A ground's citation, or its name, or its id when it cannot be read.
	 *
	 * @param string $groundId The ground.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return string
	 */
	private function groundName(string $groundId): string {
		if ($groundId === '') {
			return '';
		}

		$ground = $this->objectService->find(id: $groundId, register: 'decidiq', schema: 'confidentiality-ground', _rbac: false, _multitenancy: false);
		$data   = (array)$ground?->getObject();
		return (string)($data['citation'] ?? ($data['name'] ?? $groundId));
	}//end groundName()

	/**
	 * Read objects of one schema, in system context.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => (['register' => 'decidiq', 'schema' => $schema] + $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		$result = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$result[] = ['id' => (string)$row->getUuid()] + (array)$row->getObject();
			}
		}

		return $result;
	}//end rows()
}//end class
