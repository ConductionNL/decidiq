<?php

/**
 * Decidiq Minutes Draft Renderer
 *
 * Renders the structured Dutch minutes draft from data that has already been
 * gathered by MinutesGenerationService. Pure presentation: it performs no I/O,
 * touches no register and has no dependencies, which is exactly why it is a
 * separate collaborator — the fetching half and the formatting half of a draft
 * change for completely different reasons.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Renders the Dutch minutes draft template.
 *
 * Every optional section is produced by its own builder that returns null when
 * it has nothing to contribute; render() numbers only the sections that were
 * actually emitted, which is the behaviour the template has always had.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesDraftRenderer {
	/**
	 * The Dutch labels of the attendance statuses, in the order they are listed.
	 *
	 * @var array<string, string>
	 */
	private const ATTENDANCE_LABELS = [
		'present' => 'Aanwezig',
		'excused' => 'Afgemeld',
		'absent' => 'Afwezig',
		'proxy' => 'Vertegenwoordigd met volmacht',
	];

	/**
	 * Render the draft minutes as markdown.
	 *
	 * @param array<string,mixed> $minutes Minutes object data
	 * @param array<string,mixed> $parts The meeting data under `meeting`, and the meeting's agendaItems (sorted by
	 *                                   orderNumber), motions, votingRounds, decisions and attendance (who attended,
	 *                                   with this meeting's status; optional)
	 *
	 * @return string Markdown content for the draft minutes
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
	 */
	public function render(array $minutes, array $parts): string {
		$lines = $this->headerLines(minutes: $minutes, meeting: $parts['meeting']);

		// Section 1 is always "Opening"; the optional sections below take the
		// next numbers in the order they are emitted.
		$sectionNumber = 1;
		foreach ($this->sections(parts: $parts) as $section) {
			$sectionNumber++;
			$lines = array_merge($lines, ['## ' . $sectionNumber . '. ' . $section['title'], ''], $section['body']);
		}

		return implode("\n", array_merge($lines, $this->closingLines(sectionNumber: ($sectionNumber + 1))));
	}//end render()

	/**
	 * The optional sections that have content, in their order.
	 *
	 * @param array<string,mixed> $parts The meeting's parts, as render() reads them
	 *
	 * @return array<int, array{title: string, body: string[]}>
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function sections(array $parts): array {
		$present = [];
		foreach ($this->sectionBodies(parts: $parts) as $title => $body) {
			if ($body !== []) {
				$present[] = ['title' => $title, 'body' => $body];
			}
		}

		return $present;

	}//end sections()

	/**
	 * The body of every optional section by title, empty when it has no content.
	 *
	 * @param array<string,mixed> $parts The meeting's parts, as render() reads them
	 *
	 * @return array<string, string[]>
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function sectionBodies(array $parts): array {
		return [
			'Aanwezigheid' => $this->attendanceBody(attendance: ($parts['attendance'] ?? [])),
			'Agenda' => $this->agendaBody(agendaItems: $parts['agendaItems']),
			'Behandeling agendapunten' => $this->linesOf(rows: $parts['agendaItems'], lines: $this->treatmentLines(...)),
			'Moties en voorstellen' => $this->linesOf(rows: $parts['motions'], lines: $this->motionLines(...)),
			'Stemmingen' => $this->linesOf(rows: $parts['votingRounds'], lines: $this->votingLines(...)),
			'Besluiten' => $this->linesOf(rows: $parts['decisions'], lines: $this->decisionLines(...)),
		];

	}//end sectionBodies()

	/**
	 * The lines of every row, joined.
	 *
	 * @param array<int|string, array<string,mixed>> $rows The rows
	 * @param callable $lines Builds the lines of one row from the row and its key
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function linesOf(array $rows, callable $lines): array {
		return array_merge([], ...array_map($lines, array_values($rows), array_keys($rows)));

	}//end linesOf()

	/**
	 * The title block: minutes title, meeting, date, location and the opening section.
	 *
	 * @param array<string,mixed> $minutes Minutes object data
	 * @param array<string,mixed> $meeting Meeting object data
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function headerLines(array $minutes, array $meeting): array {
		return array_merge(
			['# ' . ($minutes['title'] ?? 'Concept Notulen'), ''],
			$this->meetingLines(meeting: $meeting),
			['', '---', '', '## 1. Opening', '', 'De vergadering wordt geopend door de voorzitter.', '']
		);
	}//end headerLines()

	/**
	 * The meeting's name, and its date and location when known.
	 *
	 * @param array<string,mixed> $meeting Meeting object data
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function meetingLines(array $meeting): array {
		$lines = ['**Vergadering:** ' . ($meeting['title'] ?? $meeting['name'] ?? 'Vergadering')];
		$date = $this->meetingDate(meeting: $meeting);
		if ($date !== '') {
			$lines[] = '**Datum:** ' . $date;
		}

		$location = $meeting['location'] ?? '';
		if ($location !== '') {
			$lines[] = '**Locatie:** ' . $location;
		}

		return $lines;
	}//end meetingLines()

	/**
	 * The meeting's date for display, or '' when it has none.
	 *
	 * @param array<string,mixed> $meeting Meeting object data
	 *
	 * @return string
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function meetingDate(array $meeting): string {
		$scheduledDate = $meeting['scheduledDate'] ?? $meeting['date'] ?? '';
		if ($scheduledDate === '') {
			return '';
		}

		return $this->formatDate(isoDate: (string)$scheduledDate);
	}//end meetingDate()

	/**
	 * Who attended, grouped per status; none when no row has a known status.
	 *
	 * @param array<int,array<string,mixed>> $attendance Who attended, with this meeting's status
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function attendanceBody(array $attendance): array {
		$groups = $this->attendanceGroups(attendance: $attendance);
		$body = [];
		foreach (self::ATTENDANCE_LABELS as $status => $label) {
			if (isset($groups[$status]) === true) {
				$body[] = sprintf('%s (%d): %s', $label, count($groups[$status]), implode(', ', $groups[$status]));
				$body[] = '';
			}
		}

		return $body;

	}//end attendanceBody()

	/**
	 * The attendees' names per known status.
	 *
	 * @param array<int,array<string,mixed>> $attendance Who attended, with this meeting's status
	 *
	 * @return array<string, string[]>
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function attendanceGroups(array $attendance): array {
		$groups = [];
		foreach ($attendance as $row) {
			$status = (string)($row['status'] ?? '');
			if (isset(self::ATTENDANCE_LABELS[$status]) === true) {
				$groups[$status][] = (string)($row['name'] ?? '');
			}
		}

		return $groups;

	}//end attendanceGroups()

	/**
	 * The agenda list; none when there are no items.
	 *
	 * @param array<int,array<string,mixed>> $agendaItems Agenda items
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function agendaBody(array $agendaItems): array {
		if (count($agendaItems) === 0) {
			return [];
		}

		$lines = $this->linesOf(rows: $agendaItems, lines: $this->agendaLine(...));
		return array_merge(['De agenda wordt vastgesteld met de volgende punten:', ''], $lines, ['']);

	}//end agendaBody()

	/**
	 * The agenda line of one item.
	 *
	 * @param array<string,mixed> $item Agenda item
	 * @param int|string $index The item's key in the list
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function agendaLine(array $item, int|string $index): array {
		return [vsprintf('%d. %s', $this->itemHeading(item: $item, index: $index))];

	}//end agendaLine()

	/**
	 * An agenda item's number and title: its orderNumber (else its position)
	 * and its title (else its name, else a numbered placeholder).
	 *
	 * @param array<string,mixed> $item Agenda item
	 * @param int|string $index The item's key in the list
	 *
	 * @return array{0: mixed, 1: mixed}
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function itemHeading(array $item, int|string $index): array {
		$order = $item['orderNumber'] ?? ($index + 1);
		return [$order, $item['title'] ?? $item['name'] ?? 'Agendapunt ' . $order];

	}//end itemHeading()

	/**
	 * The treatment block of one agenda item.
	 *
	 * @param array<string,mixed> $item Agenda item
	 * @param int|string $index The item's key in the list
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function treatmentLines(array $item, int|string $index): array {
		$lines = [vsprintf('### %d. %s', $this->itemHeading(item: $item, index: $index)), ''];
		$description = $item['description'] ?? '';
		if ($description !== '') {
			array_push($lines, $description, '');
		}

		array_push($lines, '_[Hier de bespreking van dit agendapunt invullen.]_', '');
		return $lines;

	}//end treatmentLines()

	/**
	 * The block of one motion: its title and its text.
	 *
	 * @param array<string,mixed> $motion Motion
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function motionLines(array $motion): array {
		$lines = ['**' . ($motion['title'] ?? $motion['name'] ?? 'Motie') . '**'];
		$text = $motion['text'] ?? $motion['description'] ?? '';
		if ($text !== '') {
			array_push($lines, '', $text);
		}

		$lines[] = '';
		return $lines;

	}//end motionLines()

	/**
	 * The block of one voting round: its title and its result.
	 *
	 * @param array<string,mixed> $round Voting round
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function votingLines(array $round): array {
		$lines = ['**' . ($round['title'] ?? $round['name'] ?? 'Stemming') . '**'];
		$result = $round['result'] ?? $round['outcome'] ?? '';
		if ($result !== '') {
			$lines[] = 'Uitslag: ' . $result;
		}

		$lines[] = '';
		return $lines;

	}//end votingLines()

	/**
	 * The block of one decision: its title, its outcome and its text.
	 *
	 * @param array<string,mixed> $decision Decision
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function decisionLines(array $decision): array {
		$lines = ['**' . ($decision['title'] ?? 'Besluit') . '**'];
		$outcome = $decision['outcome'] ?? '';
		if ($outcome !== '') {
			$lines[] = 'Uitkomst: ' . $this->outcomeLabel(outcome: (string)$outcome);
		}

		$text = $decision['text'] ?? $decision['description'] ?? '';
		if ($text !== '') {
			$lines[] = $text;
		}

		$lines[] = '';
		return $lines;

	}//end decisionLines()

	/**
	 * Translate a decision outcome to its Dutch label.
	 *
	 * @param string $outcome The raw outcome value
	 *
	 * @return string The Dutch label
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function outcomeLabel(string $outcome): string {
		if ($outcome === 'adopted') {
			return 'Aangenomen';
		}

		return 'Verworpen';
	}//end outcomeLabel()

	/**
	 * Build the closing section and the document footer.
	 *
	 * @param int $sectionNumber The number the closing section takes
	 *
	 * @return array<int,string> The closing lines
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function closingLines(int $sectionNumber): array {
		$lines = [];
		$lines[] = '## ' . $sectionNumber . '. Sluiting';
		$lines[] = '';
		$lines[] = 'De voorzitter sluit de vergadering.';
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '_Dit is een automatisch gegenereerd concept. Controleer en bewerk de notulen vóór vaststelling._';

		return $lines;
	}//end closingLines()

	/**
	 * Format an ISO 8601 date string to a Dutch display format.
	 *
	 * @param string $isoDate ISO 8601 date string
	 *
	 * @return string Dutch formatted date (dd-mm-yyyy HH:MM) or original string on failure
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function formatDate(string $isoDate): string {
		try {
			$date = new DateTimeImmutable($isoDate);
			return $date->format('d-m-Y H:i');
		} catch (Throwable) {
			return $isoDate;
		}

	}//end formatDate()
}//end class
