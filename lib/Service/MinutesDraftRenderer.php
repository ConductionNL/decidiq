<?php
/**
 * Decidesk Minutes Draft Renderer
 *
 * Renders the structured Dutch minutes draft from data that has already been
 * gathered by MinutesGenerationService. Pure presentation: it performs no I/O,
 * touches no register and has no dependencies, which is exactly why it is a
 * separate collaborator — the fetching half and the formatting half of a draft
 * change for completely different reasons.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

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
class MinutesDraftRenderer
{
    /**
     * Render the Dutch minutes template from the gathered data.
     *
     * @param array<string,mixed>            $minutes      Minutes object data
     * @param array<string,mixed>            $meeting      Meeting object data
     * @param array<int,array<string,mixed>> $agendaItems  Agenda items (sorted by orderNumber)
     * @param array<int,array<string,mixed>> $motions      Motions from the meeting
     * @param array<int,array<string,mixed>> $votingRounds VotingRounds from the meeting
     * @param array<int,array<string,mixed>> $decisions    Decisions from the meeting
     *
     * @return string The rendered Dutch minutes text
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function render(
        array $minutes,
        array $meeting,
        array $agendaItems,
        array $motions,
        array $votingRounds,
        array $decisions
    ): string {
        $lines = $this->headerLines(minutes: $minutes, meeting: $meeting);

        // Section 1 is always "Opening"; the optional sections below take the
        // next numbers in the order they are emitted.
        $sectionNumber = 1;

        $sections = [
            $this->agendaSection(agendaItems: $agendaItems),
            $this->treatmentSection(agendaItems: $agendaItems),
            $this->motionSection(motions: $motions),
            $this->votingSection(votingRounds: $votingRounds),
            $this->decisionSection(decisions: $decisions),
        ];

        foreach ($sections as $section) {
            if ($section === null) {
                continue;
            }

            $sectionNumber++;
            $lines[] = '## '.$sectionNumber.'. '.$section['title'];
            $lines[] = '';
            $lines   = array_merge($lines, $section['body']);
        }

        $sectionNumber++;

        return implode("\n", array_merge($lines, $this->closingLines(sectionNumber: $sectionNumber)));

    }//end render()

    /**
     * Build the document header up to and including the "Opening" section.
     *
     * @param array<string,mixed> $minutes Minutes object data
     * @param array<string,mixed> $meeting Meeting object data
     *
     * @return array<int,string> The header lines
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function headerLines(array $minutes, array $meeting): array
    {
        $meetingTitle  = $meeting['title'] ?? $meeting['name'] ?? 'Vergadering';
        $scheduledDate = $meeting['scheduledDate'] ?? $meeting['date'] ?? '';
        $location      = $meeting['location'] ?? '';

        $formattedDate = '';
        if ($scheduledDate !== '') {
            $formattedDate = $this->formatDate(isoDate: (string) $scheduledDate);
        }

        $lines   = [];
        $lines[] = '# '.($minutes['title'] ?? 'Concept Notulen');
        $lines[] = '';
        $lines[] = '**Vergadering:** '.$meetingTitle;

        if ($formattedDate !== '') {
            $lines[] = '**Datum:** '.$formattedDate;
        }

        if ($location !== '') {
            $lines[] = '**Locatie:** '.$location;
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## 1. Opening';
        $lines[] = '';
        $lines[] = 'De vergadering wordt geopend door de voorzitter.';
        $lines[] = '';

        return $lines;

    }//end headerLines()

    /**
     * Build the "Agenda" section listing the agenda items.
     *
     * @param array<int,array<string,mixed>> $agendaItems Agenda items
     *
     * @return array{title:string,body:array<int,string>}|null The section, or null when there is nothing to emit
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function agendaSection(array $agendaItems): ?array
    {
        if (count($agendaItems) === 0) {
            return null;
        }

        $body   = [];
        $body[] = 'De agenda wordt vastgesteld met de volgende punten:';
        $body[] = '';

        foreach ($agendaItems as $index => $item) {
            $order  = $item['orderNumber'] ?? ($index + 1);
            $title  = $item['title'] ?? $item['name'] ?? 'Agendapunt '.$order;
            $body[] = sprintf('%d. %s', $order, $title);
        }

        $body[] = '';

        return [
            'title' => 'Agenda',
            'body'  => $body,
        ];

    }//end agendaSection()

    /**
     * Build the "Behandeling agendapunten" section with a stub per agenda item.
     *
     * @param array<int,array<string,mixed>> $agendaItems Agenda items
     *
     * @return array{title:string,body:array<int,string>}|null The section, or null when there is nothing to emit
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function treatmentSection(array $agendaItems): ?array
    {
        if (count($agendaItems) === 0) {
            return null;
        }

        $body = [];
        foreach ($agendaItems as $index => $item) {
            $order       = $item['orderNumber'] ?? ($index + 1);
            $title       = $item['title'] ?? $item['name'] ?? 'Agendapunt '.$order;
            $description = $item['description'] ?? '';

            $body[] = sprintf('### %d. %s', $order, $title);
            $body[] = '';

            if ($description !== '') {
                $body[] = $description;
                $body[] = '';
            }

            $body[] = '_[Hier de bespreking van dit agendapunt invullen.]_';
            $body[] = '';
        }//end foreach

        return [
            'title' => 'Behandeling agendapunten',
            'body'  => $body,
        ];

    }//end treatmentSection()

    /**
     * Build the "Moties en voorstellen" section.
     *
     * @param array<int,array<string,mixed>> $motions Motions from the meeting
     *
     * @return array{title:string,body:array<int,string>}|null The section, or null when there is nothing to emit
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function motionSection(array $motions): ?array
    {
        if (count($motions) === 0) {
            return null;
        }

        $body = [];
        foreach ($motions as $motion) {
            $title = $motion['title'] ?? $motion['name'] ?? 'Motie';
            $text  = $motion['text'] ?? $motion['description'] ?? '';

            $body[] = '**'.$title.'**';

            if ($text !== '') {
                $body[] = '';
                $body[] = $text;
            }

            $body[] = '';
        }

        return [
            'title' => 'Moties en voorstellen',
            'body'  => $body,
        ];

    }//end motionSection()

    /**
     * Build the "Stemmingen" section.
     *
     * @param array<int,array<string,mixed>> $votingRounds VotingRounds from the meeting
     *
     * @return array{title:string,body:array<int,string>}|null The section, or null when there is nothing to emit
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function votingSection(array $votingRounds): ?array
    {
        if (count($votingRounds) === 0) {
            return null;
        }

        $body = [];
        foreach ($votingRounds as $round) {
            $title  = $round['title'] ?? $round['name'] ?? 'Stemming';
            $result = $round['result'] ?? $round['outcome'] ?? '';

            $body[] = '**'.$title.'**';

            if ($result !== '') {
                $body[] = 'Uitslag: '.$result;
            }

            $body[] = '';
        }

        return [
            'title' => 'Stemmingen',
            'body'  => $body,
        ];

    }//end votingSection()

    /**
     * Build the "Besluiten" section.
     *
     * @param array<int,array<string,mixed>> $decisions Decisions from the meeting
     *
     * @return array{title:string,body:array<int,string>}|null The section, or null when there is nothing to emit
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function decisionSection(array $decisions): ?array
    {
        if (count($decisions) === 0) {
            return null;
        }

        $body = [];
        foreach ($decisions as $decision) {
            $title   = $decision['title'] ?? 'Besluit';
            $text    = $decision['text'] ?? $decision['description'] ?? '';
            $outcome = $decision['outcome'] ?? '';

            $body[] = '**'.$title.'**';

            if ($outcome !== '') {
                $body[] = 'Uitkomst: '.$this->outcomeLabel(outcome: (string) $outcome);
            }

            if ($text !== '') {
                $body[] = $text;
            }

            $body[] = '';
        }//end foreach

        return [
            'title' => 'Besluiten',
            'body'  => $body,
        ];

    }//end decisionSection()

    /**
     * Translate a decision outcome to its Dutch label.
     *
     * @param string $outcome The raw outcome value
     *
     * @return string The Dutch label
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function outcomeLabel(string $outcome): string
    {
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
    private function closingLines(int $sectionNumber): array
    {
        $lines   = [];
        $lines[] = '## '.$sectionNumber.'. Sluiting';
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
    private function formatDate(string $isoDate): string
    {
        try {
            $date = new DateTimeImmutable($isoDate);
            return $date->format('d-m-Y H:i');
        } catch (Throwable) {
            return $isoDate;
        }

    }//end formatDate()
}//end class
