<?php
/**
 * Decidesk Minutes Template Renderer
 *
 * Renders the Dutch concept-minutes markdown from the objects gathered for a
 * meeting: agenda items, motions, voting rounds and decisions.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Builds the concept-minutes document, extracted from MinutesGenerationService.
 *
 * The former renderTemplate() was a 155-line method with a cyclomatic
 * complexity of 20 and an NPath complexity of 12288, because every optional
 * section was another branch in one flat body. Each section is now its own
 * method, and the running section number is the only state they share.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesTemplateRenderer
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
        array $decisions,
    ): string {
        $lines = $this->header(minutes: $minutes, meeting: $meeting);

        // Track the current section number — incremented each time a section is emitted.
        $sectionNumber = 1;

        $sections = [
            $this->agendaSection(agendaItems: $agendaItems),
            $this->agendaTreatmentSection(agendaItems: $agendaItems),
            $this->motionsSection(motions: $motions),
            $this->votingSection(votingRounds: $votingRounds),
            $this->decisionsSection(decisions: $decisions),
        ];

        foreach ($sections as $section) {
            if ($section === []) {
                continue;
            }

            $sectionNumber++;
            $lines = array_merge($lines, $this->numbered(section: $section, sectionNumber: $sectionNumber));
        }

        // Closing section — number follows whichever sections were actually emitted.
        $sectionNumber++;

        return implode(
            "\n",
            array_merge($lines, $this->closing(sectionNumber: $sectionNumber))
        );

    }//end render()

    /**
     * The title block and the fixed opening section.
     *
     * @param array<string,mixed> $minutes Minutes object data
     * @param array<string,mixed> $meeting Meeting object data
     *
     * @return array<int, string> The opening lines.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function header(array $minutes, array $meeting): array
    {
        $lines = [
            '# '.($minutes['title'] ?? 'Concept Notulen'),
            '',
            '**Vergadering:** '.($meeting['title'] ?? $meeting['name'] ?? 'Vergadering'),
        ];

        $scheduledDate = ($meeting['scheduledDate'] ?? $meeting['date'] ?? '');
        $formattedDate = '';
        if ($scheduledDate !== '') {
            $formattedDate = $this->formatDate(isoDate: $scheduledDate);
        }

        if ($formattedDate !== '') {
            $lines[] = '**Datum:** '.$formattedDate;
        }

        $location = ($meeting['location'] ?? '');
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

    }//end header()

    /**
     * The agenda listing section.
     *
     * @param array<int,array<string,mixed>> $agendaItems Agenda items
     *
     * @return array<int, string> The section lines, empty when there is nothing to render.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function agendaSection(array $agendaItems): array
    {
        if ($agendaItems === []) {
            return [];
        }

        $lines = [
            '## {n}. Agenda',
            '',
            'De agenda wordt vastgesteld met de volgende punten:',
            '',
        ];

        foreach ($agendaItems as $index => $item) {
            $order   = ($item['orderNumber'] ?? ($index + 1));
            $lines[] = sprintf('%d. %s', $order, $this->itemTitle(item: $item, order: $order));
        }

        $lines[] = '';

        return $lines;

    }//end agendaSection()

    /**
     * The per-agenda-item treatment section.
     *
     * @param array<int,array<string,mixed>> $agendaItems Agenda items
     *
     * @return array<int, string> The section lines, empty when there is nothing to render.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function agendaTreatmentSection(array $agendaItems): array
    {
        if ($agendaItems === []) {
            return [];
        }

        $lines = [
            '## {n}. Behandeling agendapunten',
            '',
        ];

        foreach ($agendaItems as $index => $item) {
            $order   = ($item['orderNumber'] ?? ($index + 1));
            $lines[] = sprintf('### %d. %s', $order, $this->itemTitle(item: $item, order: $order));
            $lines[] = '';

            $description = ($item['description'] ?? '');
            if ($description !== '') {
                $lines[] = $description;
                $lines[] = '';
            }

            $lines[] = '_[Hier de bespreking van dit agendapunt invullen.]_';
            $lines[] = '';
        }

        return $lines;

    }//end agendaTreatmentSection()

    /**
     * The motions section.
     *
     * @param array<int,array<string,mixed>> $motions Motions from the meeting
     *
     * @return array<int, string> The section lines, empty when there is nothing to render.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function motionsSection(array $motions): array
    {
        if ($motions === []) {
            return [];
        }

        $lines = [
            '## {n}. Moties en voorstellen',
            '',
        ];

        foreach ($motions as $motion) {
            $lines[] = '**'.($motion['title'] ?? $motion['name'] ?? 'Motie').'**';

            $text = ($motion['text'] ?? $motion['description'] ?? '');
            if ($text !== '') {
                $lines[] = '';
                $lines[] = $text;
            }

            $lines[] = '';
        }

        return $lines;

    }//end motionsSection()

    /**
     * The voting rounds section.
     *
     * @param array<int,array<string,mixed>> $votingRounds VotingRounds from the meeting
     *
     * @return array<int, string> The section lines, empty when there is nothing to render.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function votingSection(array $votingRounds): array
    {
        if ($votingRounds === []) {
            return [];
        }

        $lines = [
            '## {n}. Stemmingen',
            '',
        ];

        foreach ($votingRounds as $round) {
            $lines[] = '**'.($round['title'] ?? $round['name'] ?? 'Stemming').'**';

            $result = ($round['result'] ?? $round['outcome'] ?? '');
            if ($result !== '') {
                $lines[] = 'Uitslag: '.$result;
            }

            $lines[] = '';
        }

        return $lines;

    }//end votingSection()

    /**
     * The decisions section.
     *
     * @param array<int,array<string,mixed>> $decisions Decisions from the meeting
     *
     * @return array<int, string> The section lines, empty when there is nothing to render.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function decisionsSection(array $decisions): array
    {
        if ($decisions === []) {
            return [];
        }

        $lines = [
            '## {n}. Besluiten',
            '',
        ];

        foreach ($decisions as $decision) {
            $lines[] = '**'.($decision['title'] ?? 'Besluit').'**';

            $outcome = ($decision['outcome'] ?? '');
            if ($outcome !== '') {
                $lines[] = 'Uitkomst: '.$this->outcomeLabel(outcome: $outcome);
            }

            $text = ($decision['text'] ?? $decision['description'] ?? '');
            if ($text !== '') {
                $lines[] = $text;
            }

            $lines[] = '';
        }

        return $lines;

    }//end decisionsSection()

    /**
     * The fixed closing section.
     *
     * @param int $sectionNumber The number this section takes
     *
     * @return array<int, string> The closing lines.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function closing(int $sectionNumber): array
    {
        return [
            '## '.$sectionNumber.'. Sluiting',
            '',
            'De voorzitter sluit de vergadering.',
            '',
            '---',
            '',
            '_Dit is een automatisch gegenereerd concept. Controleer en bewerk de notulen vóór vaststelling._',
        ];

    }//end closing()

    /**
     * Substitute the running section number into a section's heading.
     *
     * Sections are built without knowing their number, because the number
     * depends on which earlier sections turned out to be non-empty.
     *
     * @param array<int, string> $section       The section lines
     * @param int                $sectionNumber The number this section takes
     *
     * @return array<int, string> The section with its heading numbered.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function numbered(array $section, int $sectionNumber): array
    {
        $section[0] = str_replace('{n}', (string) $sectionNumber, $section[0]);

        return $section;

    }//end numbered()

    /**
     * The display title of one agenda item.
     *
     * @param array<string,mixed> $item  The agenda item
     * @param mixed               $order The item's order number
     *
     * @return string The title.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function itemTitle(array $item, mixed $order): string
    {
        return (string) ($item['title'] ?? $item['name'] ?? 'Agendapunt '.$order);

    }//end itemTitle()

    /**
     * The Dutch label for a decision outcome.
     *
     * @param string $outcome The stored outcome value
     *
     * @return string The Dutch label.
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
