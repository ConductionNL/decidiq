<?php

/**
 * Decidesk Minutes Generation Service
 *
 * Service for generating draft minutes content from linked meeting data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service for generating draft minutes content from a linked Meeting.
 *
 * Fetches the linked Meeting's AgendaItems, Motions, VotingRounds, and Decisions
 * via OpenRegister relations and renders them into a structured Dutch text template.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesGenerationService
{
    /**
     * Constructor for MinutesGenerationService.
     *
     * @param ContainerInterface $container The DI container (for lazy OpenRegister services)
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a structured Dutch draft text for the given Minutes object.
     *
     * Fetches the linked Meeting's agenda items, motions, voting rounds, and
     * decisions from OpenRegister, then renders them into a structured Dutch
     * text template suitable for clerk review.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return string Generated Dutch draft text
     *
     * @throws \InvalidArgumentException When the Minutes object is not found
     * @throws \RuntimeException         When OpenRegister is unavailable
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function generateDraft(string $minutesId): string
    {
        $objectService = $this->getObjectService();

        // Fetch the Minutes object.
        $minutes = $objectService->getObject('decidesk', 'minutes', $minutesId);
        if ($minutes === null) {
            throw new \InvalidArgumentException("Minutes object '$minutesId' not found.");
        }

        // Resolve the linked Meeting via relations.
        $meeting      = null;
        $agendaItems  = [];
        $motions      = [];
        $votingRounds = [];
        $decisions    = [];

        $relations = $minutes['relations'] ?? [];
        foreach ($relations as $relation) {
            if (($relation['schema'] ?? '') === 'meeting') {
                try {
                    $meeting = $objectService->getObject(
                        'decidesk',
                        'meeting',
                        ($relation['objectId'] ?? $relation['id'] ?? '')
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('Decidesk: could not resolve linked Meeting', ['exception' => $e->getMessage()]);
                }

                break;
            }
        }

        if ($meeting !== null) {
            $agendaItems  = $this->fetchRelated($objectService, 'agenda-item', $meeting);
            $motions      = $this->fetchRelated($objectService, 'motion', $meeting);
            $votingRounds = $this->fetchRelated($objectService, 'voting-round', $meeting);
            $decisions    = $this->fetchRelated($objectService, 'decision', $meeting);

            // Sort agenda items by orderNumber.
            usort(
                $agendaItems,
                static function (array $a, array $b): int {
                    return ($a['orderNumber'] ?? 0) <=> ($b['orderNumber'] ?? 0);
                }
            );
        }

        return $this->renderTemplate(
            $minutes,
            $meeting,
            $agendaItems,
            $motions,
            $votingRounds,
            $decisions
        );

    }//end generateDraft()

    /**
     * Fetch related objects of the given schema from a parent object's relations.
     *
     * @param mixed  $objectService The OpenRegister ObjectService
     * @param string $schema        The schema slug to filter by
     * @param array  $parentObject  The parent object with 'relations' array
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function fetchRelated(mixed $objectService, string $schema, array $parentObject): array
    {
        $results   = [];
        $relations = $parentObject['relations'] ?? [];

        foreach ($relations as $relation) {
            if (($relation['schema'] ?? '') !== $schema) {
                continue;
            }

            $objectId = $relation['objectId'] ?? $relation['id'] ?? '';
            if (empty($objectId) === true) {
                continue;
            }

            try {
                $obj = $objectService->getObject('decidesk', $schema, $objectId);
                if ($obj !== null) {
                    $results[] = $obj;
                }
            } catch (\Throwable $e) {
                $this->logger->debug(
                    "Decidesk: could not fetch $schema relation",
                    ['id' => $objectId, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return $results;

    }//end fetchRelated()

    /**
     * Render the Dutch minutes draft template.
     *
     * @param array      $minutes      The Minutes object
     * @param array|null $meeting      The linked Meeting object (null if not found)
     * @param array      $agendaItems  Ordered AgendaItem objects
     * @param array      $motions      Motion objects
     * @param array      $votingRounds VotingRound objects
     * @param array      $decisions    Decision objects
     *
     * @return string Rendered Dutch draft text
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function renderTemplate(
        array $minutes,
        ?array $meeting,
        array $agendaItems,
        array $motions,
        array $votingRounds,
        array $decisions,
    ): string {
        $lines = [];

        $title       = $minutes['title'] ?? 'Concept notulen';
        $lines[]     = "# $title";
        $lines[]     = '';
        $disclaimer  = '*Dit is een automatisch gegenereerd concept. De griffier dient dit concept';
        $disclaimer .= ' te controleren en aan te vullen voor indiening ter goedkeuring.*';
        $lines[]     = $disclaimer;
        $lines[]     = '';

        if ($meeting !== null) {
            $lines[] = '## Vergadergegevens';
            $lines[] = '';
            $lines[] = '| Gegeven | Waarde |';
            $lines[] = '|---------|--------|';
            $lines[] = '| Vergadering | '.($meeting['title'] ?? '—').' |';
            $lines[] = '| Datum | '.$this->formatDate(datetime: ($meeting['scheduledDate'] ?? null)).' |';
            $lines[] = '| Locatie | '.($meeting['location'] ?? '—').' |';
            $lines[] = '| Type | '.($meeting['meetingType'] ?? '—').' |';
            $lines[] = '| Modus | '.($meeting['meetingMode'] ?? '—').' |';
            $lines[] = '';
        }

        $lines[] = '## Opening';
        $lines[] = '';
        $lines[] = 'De vergadering wordt geopend door de voorzitter. [Tijdstip invoegen]';
        $lines[] = '';

        if (empty($agendaItems) === false) {
            $lines[] = '## Agenda';
            $lines[] = '';
            foreach ($agendaItems as $index => $item) {
                $number     = $item['orderNumber'] ?? ($index + 1);
                $itemTitle  = $item['title'] ?? "Agendapunt $number";
                $itemType   = $item['itemType'] ?? '';
                $typeSuffix = '';
                if ($itemType !== '') {
                    $typeSuffix = " *(${itemType})*";
                }

                $lines[] = "### {$number}. {$itemTitle}".$typeSuffix;
                $lines[] = '';
                if (empty($item['description']) === false) {
                    $lines[] = $item['description'];
                    $lines[] = '';
                }

                // Find motions linked to this agenda item.
                $agendaItemId  = $item['id'] ?? $item['uuid'] ?? '';
                $linkedMotions = array_filter(
                        $motions,
                        static function (array $m) use ($agendaItemId): bool {
                            foreach (($m['relations'] ?? []) as $rel) {
                                if (($rel['schema'] ?? '') === 'agenda-item' && ($rel['objectId'] ?? $rel['id'] ?? '') === $agendaItemId) {
                                    return true;
                                }
                            }

                            return false;
                        }
                        );

                foreach ($linkedMotions as $motion) {
                    $lines[] = '**Motie/voorstel:** '.($motion['title'] ?? '—');
                    $lines[] = '';
                    if (empty($motion['text']) === false) {
                        $lines[] = $motion['text'];
                        $lines[] = '';
                    }

                    // Find voting rounds for this motion.
                    $motionId           = $motion['id'] ?? $motion['uuid'] ?? '';
                    $linkedVotingRounds = array_filter(
                            $votingRounds,
                            static function (array $vr) use ($motionId): bool {
                                foreach (($vr['relations'] ?? []) as $rel) {
                                    if (($rel['schema'] ?? '') === 'motion' && ($rel['objectId'] ?? $rel['id'] ?? '') === $motionId) {
                                        return true;
                                    }
                                }

                                return false;
                            }
                            );

                    foreach ($linkedVotingRounds as $vr) {
                        $result       = $vr['result'] ?? '—';
                        $votesFor     = $vr['votesFor'] ?? '—';
                        $votesAgainst = $vr['votesAgainst'] ?? '—';
                        $votesAbstain = $vr['votesAbstain'] ?? '—';
                        $lines[]      = "**Stemuitslag:** {$result} (voor: {$votesFor}, tegen: {$votesAgainst}, onthoudingen: {$votesAbstain})";
                        $lines[]      = '';
                    }
                }//end foreach
            }//end foreach
        }//end if

        if (empty($decisions) === false) {
            $lines[] = '## Besluiten';
            $lines[] = '';
            foreach ($decisions as $index => $decision) {
                $num     = $index + 1;
                $dTitle  = $decision['title'] ?? "Besluit $num";
                $dText   = $decision['text'] ?? '—';
                $outcome = $decision['outcome'] ?? '—';
                $lines[] = "**Besluit {$num}:** {$dTitle}";
                $lines[] = '';
                $lines[] = $dText;
                $lines[] = '';
                $lines[] = "*Uitkomst: {$outcome}*";
                $lines[] = '';
                if (empty($decision['legalBasis']) === false) {
                    $lines[] = "*Juridische grondslag: {$decision['legalBasis']}*";
                    $lines[] = '';
                }
            }//end foreach
        }//end if

        $lines[] = '## Sluiting';
        $lines[] = '';
        $lines[] = 'De vergadering wordt gesloten. [Tijdstip invoegen]';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '*Concept opgesteld op: '.date('d-m-Y H:i').'*';

        return implode("\n", $lines);

    }//end renderTemplate()

    /**
     * Format a datetime string to a Dutch date representation.
     *
     * @param string|null $datetime ISO 8601 datetime string or null
     *
     * @return string Formatted date or '—' if null
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function formatDate(?string $datetime): string
    {
        if (empty($datetime) === true) {
            return '—';
        }

        try {
            $dt = new \DateTimeImmutable($datetime);
            return $dt->format('d-m-Y H:i');
        } catch (\Throwable $e) {
            return $datetime;
        }

    }//end formatDate()

    /**
     * Lazily resolve the OpenRegister ObjectService from the DI container.
     *
     * @return mixed The ObjectService instance
     *
     * @throws \RuntimeException When OpenRegister ObjectService is unavailable
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function getObjectService(): mixed
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OpenRegister ObjectService is not available. Ensure OpenRegister is installed and enabled.',
                0,
                $e
            );
        }

    }//end getObjectService()
}//end class
