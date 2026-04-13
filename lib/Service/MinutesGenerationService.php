<?php

/**
 * Minutes Generation Service
 *
 * Generates draft minutes content from linked meeting data.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that generates draft minutes content from a linked meeting.
 *
 * Fetches the linked Meeting via OpenRegister relations, retrieves its
 * AgendaItems (ordered by orderNumber), Motions, VotingRounds, and Decisions,
 * and renders them into a structured Dutch text template.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesGenerationService
{

    /**
     * Constructor for MinutesGenerationService.
     *
     * @param IAppConfig         $appConfig The app config interface
     * @param ContainerInterface $container The container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate draft minutes content from the linked meeting.
     *
     * Fetches the Minutes object to find its linked Meeting, then retrieves
     * AgendaItems, Motions, VotingRounds, and Decisions to render a
     * structured Dutch text template.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return string The generated draft content in Dutch
     *
     * @throws \RuntimeException When the Minutes object or linked Meeting cannot be found
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function generateDraft(string $minutesId): string
    {
        $objectService = $this->getObjectService();
        $register      = $this->getRegister();

        // Fetch the minutes object.
        $minutes = $objectService->findObject(register: $register, schema: 'minutes', id: $minutesId);
        if (empty($minutes) === true) {
            throw new \RuntimeException('Notulen object niet gevonden: '.$minutesId);
        }

        // Find the linked meeting via relations.
        $meetingId = $this->findRelatedObjectId($minutes, 'meeting');
        if ($meetingId === null) {
            throw new \RuntimeException('Geen vergadering gekoppeld aan deze notulen');
        }

        $meeting = $objectService->findObject(register: $register, schema: 'meeting', id: $meetingId);
        if (empty($meeting) === true) {
            throw new \RuntimeException('Gekoppelde vergadering niet gevonden: '.$meetingId);
        }

        // Fetch agenda items sorted by orderNumber.
        $agendaItems = $this->fetchRelatedObjects($objectService, $register, 'agenda-item', 'meeting', $meetingId);
        usort($agendaItems, function ($a, $b) {
            return (int) ($a['orderNumber'] ?? 0) - (int) ($b['orderNumber'] ?? 0);
        });

        // Fetch motions, voting rounds, and decisions for context.
        $motions      = $this->fetchRelatedObjects($objectService, $register, 'motion', 'agendaItem', null);
        $votingRounds = $this->fetchRelatedObjects($objectService, $register, 'voting-round', 'motion', null);
        $decisions    = $this->fetchRelatedObjects($objectService, $register, 'decision', 'motion', null);

        return $this->renderTemplate($meeting, $agendaItems, $motions, $votingRounds, $decisions);
    }//end generateDraft()

    /**
     * Find a related object ID from the relations array.
     *
     * @param array  $object       The source object
     * @param string $relationType The relation type name
     *
     * @return string|null The related object ID or null
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function findRelatedObjectId(array $object, string $relationType): ?string
    {
        $relations = ($object['relations'] ?? []);
        foreach ($relations as $relation) {
            if (isset($relation['schema']) === true
                && strtolower($relation['schema']) === strtolower($relationType)
            ) {
                return ($relation['objectId'] ?? $relation['id'] ?? null);
            }
        }

        return null;
    }//end findRelatedObjectId()

    /**
     * Fetch related objects from a schema with optional filtering.
     *
     * @param object      $objectService The ObjectService instance
     * @param string      $register      The register slug
     * @param string      $schema        The schema slug
     * @param string      $relationKey   The relation key for filtering
     * @param string|null $relationValue The relation value to filter by
     *
     * @return array The fetched objects
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function fetchRelatedObjects(
        object $objectService,
        string $register,
        string $schema,
        string $relationKey,
        ?string $relationValue,
    ): array {
        try {
            $params = [];
            if ($relationValue !== null) {
                $params[$relationKey] = $relationValue;
            }

            $result = $objectService->findObjects(register: $register, schema: $schema, params: $params);
            return ($result['results'] ?? $result ?? []);
        } catch (\Throwable $e) {
            $this->logger->warning('MinutesGenerationService: failed to fetch '.$schema, ['exception' => $e->getMessage()]);
            return [];
        }
    }//end fetchRelatedObjects()

    /**
     * Render the structured Dutch minutes template.
     *
     * @param array $meeting      The meeting data
     * @param array $agendaItems  The agenda items
     * @param array $motions      The motions
     * @param array $votingRounds The voting rounds
     * @param array $decisions    The decisions
     *
     * @return string The rendered template
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function renderTemplate(
        array $meeting,
        array $agendaItems,
        array $motions,
        array $votingRounds,
        array $decisions,
    ): string {
        $title         = ($meeting['title'] ?? 'Vergadering');
        $scheduledDate = ($meeting['scheduledDate'] ?? '');
        $location      = ($meeting['location'] ?? '');

        $lines   = [];
        $lines[] = 'NOTULEN';
        $lines[] = $title;
        $lines[] = '';

        if (empty($scheduledDate) === false) {
            $dateFormatted = date('d-m-Y H:i', strtotime($scheduledDate));
            $lines[]       = 'Datum: '.$dateFormatted;
        }

        if (empty($location) === false) {
            $lines[] = 'Locatie: '.$location;
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        if (empty($agendaItems) === true) {
            $lines[] = 'Geen agendapunten gevonden voor deze vergadering.';
            return implode("\n", $lines);
        }

        foreach ($agendaItems as $index => $item) {
            $orderNumber = ($item['orderNumber'] ?? ($index + 1));
            $itemTitle   = ($item['title'] ?? 'Agendapunt '.($index + 1));
            $itemType    = ($item['itemType'] ?? 'informational');

            $lines[] = 'Agendapunt '.$orderNumber.' — '.$itemTitle;
            $lines[] = 'Type: '.$this->translateItemType($itemType);

            if (empty($item['description']) === false) {
                $lines[] = $item['description'];
            }

            // Find motions for this agenda item.
            $itemMotions = $this->filterByRelation($motions, 'agendaItem', ($item['id'] ?? ''));
            foreach ($itemMotions as $motion) {
                $lines[] = '';
                $lines[] = 'Motie: '.($motion['title'] ?? 'Zonder titel');
                $lines[] = 'Indiener: '.($motion['proposer'] ?? 'Onbekend');
                $lines[] = 'Status: '.$this->translateLifecycle(($motion['lifecycle'] ?? ''));

                // Find voting rounds for this motion.
                $motionVotes = $this->filterByRelation($votingRounds, 'motion', ($motion['id'] ?? ''));
                foreach ($motionVotes as $vote) {
                    $lines[] = 'Stemming: voor '.($vote['votesFor'] ?? 0)
                        .', tegen '.($vote['votesAgainst'] ?? 0)
                        .', onthoudingen '.($vote['votesAbstain'] ?? 0)
                        .' — resultaat: '.$this->translateResult(($vote['result'] ?? ''));
                }

                // Find decisions for this motion.
                $motionDecisions = $this->filterByRelation($decisions, 'motion', ($motion['id'] ?? ''));
                foreach ($motionDecisions as $decision) {
                    $lines[] = 'Besluit: '.($decision['title'] ?? '');
                    $lines[] = ($decision['text'] ?? '');
                }
            }//end foreach

            $lines[] = '';
        }//end foreach

        $lines[] = '---';
        $lines[] = 'Einde notulen.';

        return implode("\n", $lines);
    }//end renderTemplate()

    /**
     * Filter objects by a relation key matching a given ID.
     *
     * @param array  $objects     The objects to filter
     * @param string $relationKey The relation key
     * @param string $targetId    The target ID to match
     *
     * @return array Filtered objects
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function filterByRelation(array $objects, string $relationKey, string $targetId): array
    {
        if (empty($targetId) === true) {
            return [];
        }

        return array_filter($objects, function ($obj) use ($relationKey, $targetId) {
            $relations = ($obj['relations'] ?? []);
            foreach ($relations as $relation) {
                $schema = strtolower(($relation['schema'] ?? ''));
                if ($schema === strtolower($relationKey)) {
                    $relId = ($relation['objectId'] ?? $relation['id'] ?? '');
                    if ($relId === $targetId) {
                        return true;
                    }
                }
            }

            return false;
        });
    }//end filterByRelation()

    /**
     * Translate agenda item type to Dutch.
     *
     * @param string $type The item type
     *
     * @return string Translated type
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function translateItemType(string $type): string
    {
        $translations = [
            'informational' => 'Ter informatie',
            'discussion'    => 'Bespreekstuk',
            'decision'      => 'Besluitstuk',
        ];

        return ($translations[$type] ?? $type);
    }//end translateItemType()

    /**
     * Translate lifecycle value to Dutch.
     *
     * @param string $lifecycle The lifecycle value
     *
     * @return string Translated lifecycle
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function translateLifecycle(string $lifecycle): string
    {
        $translations = [
            'submitted'  => 'Ingediend',
            'debating'   => 'In debat',
            'voting'     => 'In stemming',
            'adopted'    => 'Aangenomen',
            'rejected'   => 'Verworpen',
            'withdrawn'  => 'Ingetrokken',
        ];

        return ($translations[$lifecycle] ?? $lifecycle);
    }//end translateLifecycle()

    /**
     * Translate voting result to Dutch.
     *
     * @param string $result The voting result
     *
     * @return string Translated result
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function translateResult(string $result): string
    {
        $translations = [
            'adopted'  => 'Aangenomen',
            'rejected' => 'Verworpen',
            'tied'     => 'Gelijkspel',
            'invalid'  => 'Ongeldig',
        ];

        return ($translations[$result] ?? $result);
    }//end translateResult()

    /**
     * Get the ObjectService from OpenRegister via the container.
     *
     * @return object The ObjectService instance
     *
     * @throws \RuntimeException When ObjectService is not available
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenRegister ObjectService is niet beschikbaar: '.$e->getMessage());
        }
    }//end getObjectService()

    /**
     * Get the configured register slug.
     *
     * @return string The register slug
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function getRegister(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', 'decidesk');
    }//end getRegister()
}//end class
