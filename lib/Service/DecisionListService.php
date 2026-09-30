<?php

/**
 * Decidiq DecisionListService
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;

/**
 * Renders the decision list of a meeting: every decision taken under its
 * agenda items with title, outcome and vote totals, headed with the approval
 * date and the signers recorded on the minutes, stored as Besluitenlijst.pdf
 * in the meeting folder.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
 */
class DecisionListService {
	/**
	 * The file name, without extension.
	 */
	public const BASE_NAME = 'Besluitenlijst';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param FilinqPdf              $pdf           PDF rendering through filinq.
	 * @param MeetingFolderService   $folders       The meeting folder writer.
	 * @param IL10N                  $l10n          Translations.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly FilinqPdf $pdf,
		private readonly MeetingFolderService $folders,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Render the decision list and store it in the meeting folder.
	 *
	 * Without filinq the HTML is stored instead, with a note, as the minutes
	 * document does.
	 *
	 * @param string              $meetingId The meeting.
	 * @param array<string,mixed> $minutes   The minutes (approvedAt, signedBy).
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @throws CaseSystemException When the meeting does not exist or the file cannot be stored.
	 *
	 * @return array{name:string,path:string,format:string,content:string,note?:string}
	 */
	public function render(string $meetingId, array $minutes): array {
		$meeting = $this->meeting(meetingId: $meetingId);
		$html    = $this->html(meeting: $meeting, decisions: $this->decisions(meetingId: $meetingId), minutes: $minutes);
		$title   = $this->l10n->t('Decision list') . ' ' . (string)($meeting['title'] ?? '');
		$content = $this->pdf->fromHtml(html: $html, title: $title, context: 'decision list');
		$result  = ['name' => self::BASE_NAME . '.pdf', 'format' => 'pdf'];
		if ($content === null) {
			$content = $html;
			$note    = $this->l10n->t('filinq is not installed, so the decision list was saved as HTML instead of PDF.');
			$result  = ['name' => self::BASE_NAME . '.html', 'format' => 'html', 'note' => $note];
		}

		$path = $this->folders->writeMeetingFile(meeting: $meeting, subfolder: 'Minutes', fileName: $result['name'], content: $content);
		if ($path === null) {
			throw new CaseSystemException(message: 'The decision list could not be stored: the Files backend is unavailable.', status: 503);
		}

		return $result + ['path' => $path, 'content' => $content];
	}//end render()

	/**
	 * The decision list as HTML.
	 *
	 * @param array<string,mixed>            $meeting   The meeting.
	 * @param list<array<string,mixed>>      $decisions The decisions, each with its votes.
	 * @param array<string,mixed>            $minutes   The minutes.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return string
	 */
	public function html(array $meeting, array $decisions, array $minutes): string {
		$esc     = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
		$signers = implode(', ', array_map('strval', (array)($minutes['signedBy'] ?? [])));
		$html    = '<h1>' . $esc($this->l10n->t('Decision list')) . '</h1>';
		$html   .= '<p>' . $esc((string)($meeting['title'] ?? '')) . ', ' . $esc(substr((string)($meeting['scheduledDate'] ?? ''), 0, 10)) . '</p>';
		$html   .= '<p>' . $esc($this->l10n->t('Minutes approved on %1$s', [substr((string)($minutes['approvedAt'] ?? ''), 0, 10)])) . '</p>';
		$html   .= '<p>' . $esc($this->l10n->t('Signed by %1$s', [$signers])) . '</p>';
		$html   .= '<table><thead><tr><th>' . $esc($this->l10n->t('Decision')) . '</th><th>' . $esc($this->l10n->t('Outcome'));
		$html   .= '</th><th>' . $esc($this->l10n->t('Votes')) . '</th></tr></thead><tbody>';
		foreach ($decisions as $decision) {
			$html .= '<tr><td>' . $esc($decision['title'] ?? '') . '</td><td>' . $esc($this->outcome(outcome: (string)($decision['outcome'] ?? '')));
			$html .= '</td><td>' . $esc($this->votes(votes: $decision['votes'] ?? null)) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}//end html()

	/**
	 * The meeting's decisions, in agenda order, each with the totals of the
	 * voting round that decided it.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return list<array<string,mixed>>
	 */
	public function decisions(string $meetingId): array {
		$order = [];
		foreach ($this->rows(schema: 'agenda-item', filters: ['meeting' => $meetingId]) as $item) {
			$order[(string)($item['id'] ?? '')] = (int)($item['orderNumber'] ?? 0);
		}

		$decisions = [];
		foreach ($this->rows(schema: 'decision', filters: ['meeting' => $meetingId]) as $decision) {
			$decisions[(string)($decision['id'] ?? '')] = $decision;
		}

		foreach (array_keys($order) as $itemId) {
			foreach ($this->rows(schema: 'decision', filters: ['agendaItem' => $itemId]) as $decision) {
				$decisions[(string)($decision['id'] ?? '')] = $decision;
			}
		}

		$rounds = $this->rows(schema: 'voting-round', filters: ['_relations.meeting' => $meetingId]);
		foreach ($decisions as $id => $decision) {
			$decisions[$id]['votes'] = $this->roundFor(decisionId: (string)$id, rounds: $rounds);
		}

		$list = array_values($decisions);
		usort(
			$list,
			static fn (array $one, array $two): int => (
				($order[(string)($one['agendaItem'] ?? '')] ?? PHP_INT_MAX) <=> ($order[(string)($two['agendaItem'] ?? '')] ?? PHP_INT_MAX)
			)
		);

		return $list;
	}//end decisions()

	/**
	 * The totals of the round whose relations name the decision.
	 *
	 * @param string                    $decisionId The decision.
	 * @param list<array<string,mixed>> $rounds     The meeting's voting rounds.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return array{for:int,against:int,abstain:int}|null
	 */
	private function roundFor(string $decisionId, array $rounds): ?array {
		foreach ($rounds as $round) {
			$related = array_map(static fn (mixed $rel): string => (string)(((array)$rel)['id'] ?? ''), (array)($round['relations'] ?? []));
			if (in_array($decisionId, $related, true) === true || (string)($round['decision'] ?? '') === $decisionId) {
				return [
					'for' => (int)($round['votesFor'] ?? 0),
					'against' => (int)($round['votesAgainst'] ?? 0),
					'abstain' => (int)($round['votesAbstain'] ?? 0),
				];
			}
		}

		return null;
	}//end roundFor()

	/**
	 * The outcome as a reader says it.
	 *
	 * @param string $outcome adopted, rejected or empty.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return string
	 */
	private function outcome(string $outcome): string {
		return match ($outcome) {
			'adopted' => $this->l10n->t('Adopted'),
			'rejected' => $this->l10n->t('Rejected'),
			default => $this->l10n->t('No outcome recorded'),
		};
	}//end outcome()

	/**
	 * The vote totals as a reader says them.
	 *
	 * @param mixed $votes The totals, or null.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return string
	 */
	private function votes(mixed $votes): string {
		if (is_array($votes) === false) {
			return $this->l10n->t('No recorded vote');
		}

		return $this->l10n->t('%1$d for, %2$d against, %3$d abstained', [(int)$votes['for'], (int)$votes['against'], (int)$votes['abstain']]);
	}//end votes()

	/**
	 * Read the meeting.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @throws CaseSystemException When it does not exist.
	 *
	 * @return array<string,mixed>
	 */
	private function meeting(string $meetingId): array {
		$entity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting', _rbac: false, _multitenancy: false);
		if ($entity === null) {
			throw new CaseSystemException(message: 'Meeting not found', status: 404);
		}

		return ['id' => $meetingId] + (array)$entity->jsonSerialize();
	}//end meeting()

	/**
	 * Read objects of one schema, in system context.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
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
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$result[] = ['id' => (string)$row->getUuid()] + (array)$row->jsonSerialize();
			}
		}

		return $result;
	}//end rows()
}//end class
