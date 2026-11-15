<?php

/**
 * Decidiq Minutes Draft Composer
 *
 * Turns aligned transcript segments plus the recorded outcomes (voting rounds,
 * decisions) into provenance-stamped draft sections: it scopes the material to
 * each agenda item, assembles the Dutch prompt, and cross-checks the returned
 * summary against the record.
 *
 * Pure composition — it never talks to OpenRegister or TaskProcessing. The AI
 * call itself arrives as a `$run` callable supplied by MinutesDraftService,
 * which keeps this class trivially unit-testable and keeps the "fetch, run,
 * persist" orchestration in the service where it belongs.
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
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Composes provenance-stamped minutes-draft sections from transcript material.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class MinutesDraftComposer {
	/**
	 * Build the per-section draft (or a flat whole-meeting section).
	 *
	 * Sections are produced per agenda item when the transcript carries an
	 * agenda-item timeline AND agenda items are known; otherwise a single flat
	 * whole-meeting section is returned.
	 *
	 * @param array<int,array<string,mixed>> $agendaItems Agenda items.
	 * @param MinutesDraftRun $run The material, provenance and prompt runner.
	 *
	 * @return array<int,array<string,mixed>> The draft sections.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function buildSections(array $agendaItems, MinutesDraftRun $run): array {
		if ($agendaItems === [] || $this->hasTimeline(segments: $run->segments()) === false) {
			// Flat whole-meeting fallback.
			return [$this->buildSection(heading: ['agendaItem' => '', 'title' => 'Volledige vergadering'], run: $run)];
		}

		$sections = [];
		foreach ($agendaItems as $item) {
			$sections[] = $this->buildItemSection(item: $item, run: $run);
		}

		return $sections;
	}//end buildSections()

	/**
	 * Whether any segment is aligned to an agenda item.
	 *
	 * @param array<int,array<string,mixed>> $segments Aligned transcript segments.
	 *
	 * @return bool True when at least one segment carries an agendaItem.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function hasTimeline(array $segments): bool {
		foreach ($segments as $segment) {
			if (is_array($segment) === true && ($segment['agendaItem'] ?? '') !== '') {
				return true;
			}
		}

		return false;
	}//end hasTimeline()

	/**
	 * Build one agenda-item section from the material scoped to that item.
	 *
	 * @param array<string,mixed> $item The agenda item.
	 * @param MinutesDraftRun $run The whole meeting's run.
	 *
	 * @return array<string,mixed> The section.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function buildItemSection(array $item, MinutesDraftRun $run): array {
		$itemId = (string)($item['id'] ?? ($item['uuid'] ?? ''));
		$itemTitle = (string)($item['title'] ?? ($item['name'] ?? 'Agendapunt'));

		return $this->buildSection(
			heading: ['agendaItem' => $itemId, 'title' => $itemTitle],
			run: $run->forItem(agendaItemId: $itemId)
		);

	}//end buildItemSection()

	/**
	 * Build one provenance-stamped section with cross-checked suggestions.
	 *
	 * @param array{agendaItem: string, title: string} $heading Agenda item UUID ('' for flat) and title.
	 * @param MinutesDraftRun $run The run scoped to this section.
	 *
	 * @return array<string,mixed> The section.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function buildSection(array $heading, MinutesDraftRun $run): array {
		$votes = $run->outcomes()->votingRounds();
		$decisions = $run->outcomes()->decisions();
		$summary = $run->summarise(
			prompt: $this->assemblePrompt(title: $heading['title'], segments: $run->segments(), votes: $votes, decisions: $decisions)
		);

		return $heading + [
			'summary' => $summary,
			'suggestions' => $this->crossCheck(summary: $summary, votingRounds: $votes, decisions: $decisions),
			'provenance' => $run->provenance(),
		];

	}//end buildSection()

	/**
	 * Cross-check the AI summary's suggested outcomes against the recorded record.
	 *
	 * For each recorded decision/voting outcome whose title appears in the
	 * summary, emit a `matched` suggestion that links to the record; when the
	 * summary mentions an outcome with no recorded match it is emitted
	 * `unverified`. The heuristic is intentionally conservative — unverified is
	 * the safe default.
	 *
	 * @param string $summary The AI summary text.
	 * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds.
	 * @param array<int,array<string,mixed>> $decisions Recorded decisions.
	 *
	 * @return array<int,array<string,mixed>> Suggestions with match/unverified flags.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function crossCheck(string $summary, array $votingRounds, array $decisions): array {
		$lowerSummary = mb_strtolower($summary);
		$suggestions = [];

		foreach ($this->collectRecords(votingRounds: $votingRounds, decisions: $decisions) as $record) {
			$data = $record['data'];
			$title = (string)($data['title'] ?? ($data['name'] ?? ''));
			if ($title === '') {
				continue;
			}

			$matched = (str_contains($lowerSummary, mb_strtolower($title)) === true);
			$linkedId = '';
			if ($matched === true) {
				$linkedId = (string)($data['id'] ?? ($data['uuid'] ?? ''));
			}

			$suggestions[] = [
				'title' => $title,
				'recordType' => $record['type'],
				'linkedId' => $linkedId,
				'unverified' => ($matched === false),
			];
		}//end foreach

		return $suggestions;
	}//end crossCheck()

	/**
	 * Flatten decisions and voting rounds into one type-tagged record list.
	 *
	 * Decisions are listed before voting rounds — the order the suggestions are
	 * emitted in.
	 *
	 * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds.
	 * @param array<int,array<string,mixed>> $decisions Recorded decisions.
	 *
	 * @return array<int,array<string,mixed>> Records as {type, data}.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function collectRecords(array $votingRounds, array $decisions): array {
		$records = [];

		foreach ($decisions as $decision) {
			if (is_array($decision) === true) {
				$records[] = ['type' => 'decision', 'data' => $decision];
			}
		}

		foreach ($votingRounds as $round) {
			if (is_array($round) === true) {
				$records[] = ['type' => 'voting-round', 'data' => $round];
			}
		}

		return $records;
	}//end collectRecords()

	/**
	 * Assemble the prompt for one agenda item (or the whole meeting).
	 *
	 * @param string $title Section title.
	 * @param array<int,array<string,mixed>> $segments Segments in scope.
	 * @param array<int,array<string,mixed>> $votes Voting rounds in scope.
	 * @param array<int,array<string,mixed>> $decisions Decisions in scope.
	 *
	 * @return string The assembled prompt.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function assemblePrompt(string $title, array $segments, array $votes, array $decisions): string {
		$lines = [];
		$lines[] = 'Vat de bespreking van het volgende agendapunt zakelijk samen in het Nederlands. '
			. 'Noem genomen besluiten en actiepunten. Verzin niets dat niet in het transcript staat.';
		$lines[] = '';
		$lines[] = 'Agendapunt: ' . $title;
		$lines[] = '';
		$lines[] = 'Transcript:';

		foreach ($segments as $segment) {
			$label = (string)($segment['speakerLabel'] ?? '');
			$text = (string)($segment['text'] ?? '');
			$prefix = '';
			if ($label !== '') {
				$prefix = $label . ': ';
			}

			$lines[] = $prefix . $text;
		}

		if ($votes !== [] || $decisions !== []) {
			$lines[] = '';
			$lines[] = 'Vastgelegde uitkomsten (ter referentie, niet verzinnen):';
			$lines = array_merge($lines, $this->outcomeLines(votes: $votes, decisions: $decisions));
		}

		return implode("\n", $lines);
	}//end assemblePrompt()

	/**
	 * Render the "recorded outcomes" reference block of the prompt.
	 *
	 * @param array<int,array<string,mixed>> $votes Voting rounds in scope.
	 * @param array<int,array<string,mixed>> $decisions Decisions in scope.
	 *
	 * @return array<int,string> The rendered lines.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function outcomeLines(array $votes, array $decisions): array {
		$lines = [];

		foreach ($decisions as $decision) {
			if (is_array($decision) === true) {
				$lines[] = '- Besluit: ' . ((string)($decision['title'] ?? ''));
			}
		}

		foreach ($votes as $vote) {
			if (is_array($vote) === true) {
				$lines[] = '- Stemming: ' . ((string)($vote['title'] ?? '')) . ' (' . ((string)($vote['result'] ?? ($vote['outcome'] ?? ''))) . ')';
			}
		}

		return $lines;
	}//end outcomeLines()
}//end class
