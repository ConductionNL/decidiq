<?php
/**
 * Decidesk MCP Handler — decidesk.getMeetingDetails
 *
 * Fetches one meeting with its agenda items, decisions and action items inlined,
 * after checking that the caller is a participant of that meeting or an admin.
 *
 * @category Mcp
 * @package  OCA\Decidesk\Mcp\Handler
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Mcp\Handler;

use Throwable;

/**
 * Handler for the decidesk.getMeetingDetails MCP tool.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class GetMeetingDetailsHandler extends AbstractToolHandler
{
    /**
     * Handle decidesk.getMeetingDetails.
     *
     * Argument validation runs BEFORE authorisation, which runs BEFORE the
     * related-object queries (design D4).
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function handle(array $args): array
    {
        $rawUuid = $args['meetingUuid'] ?? null;

        $invalid = $this->validateMeetingUuid(meetingUuid: $rawUuid);
        if ($invalid !== null) {
            return $invalid;
        }

        $meetingUuid = (string) $rawUuid;

        $meeting = $this->loadMeeting(meetingUuid: $meetingUuid);
        if ($meeting === null) {
            return $this->meetingNotFound();
        }

        $isAuthorised = $this->requireParticipantOrAdmin(
            meetingUuid: $meetingUuid,
            userId: $this->currentUserId(),
        );
        if ($isAuthorised === false) {
            return $this->notAParticipant();
        }

        try {
            return $this->collect(meetingUuid: $meetingUuid, meeting: $meeting);
        } catch (Throwable $error) {
            return $this->internalError(
                tool: 'getMeetingDetails',
                message: 'Failed to retrieve meeting details. See server log for details.',
                error: $error,
                context: ['meetingUuid' => $meetingUuid]
            );
        }//end try

    }//end handle()

    /**
     * Fetch the related objects and shape the full details payload.
     *
     * @param string               $meetingUuid The validated meeting UUID
     * @param array<string, mixed> $meeting     The already-loaded meeting object
     *
     * @return array<string, mixed>
     */
    private function collect(string $meetingUuid, array $meeting): array
    {
        $agenda = $this->section(
            raw: $this->relatedObjects(schema: 'agenda-item', meetingUuid: $meetingUuid),
            kind: 'agendaItem',
            labelKeys: ['title', 'subject'],
            fallback: 'Agenda item'
        );

        $decisions = $this->section(
            raw: $this->relatedObjects(schema: 'decision', meetingUuid: $meetingUuid),
            kind: 'decision',
            labelKeys: ['title', 'text'],
            fallback: 'Decision'
        );

        $actions = $this->section(
            raw: $this->relatedObjects(schema: 'action-item', meetingUuid: $meetingUuid),
            kind: 'actionItem',
            labelKeys: ['title', 'name'],
            fallback: 'Action item'
        );

        // The meeting itself is always the first source, followed by its
        // agenda items, decisions and action items in that order.
        $meetingSource = $this->makeSource(
            kind: 'meeting',
            uuid: $meetingUuid,
            label: $this->pickLabel(item: $meeting, keys: ['title'], fallback: 'Meeting')
        );

        $sources = array_merge(
            [$meetingSource],
            $agenda['sources'],
            $decisions['sources'],
            $actions['sources']
        );

        return $this->withSources(
            result: [
                'success'     => true,
                'meeting'     => $meeting,
                'agendaItems' => $agenda['data'],
                'decisions'   => $decisions['data'],
                'actionItems' => $actions['data'],
            ],
            sources: $sources
        );

    }//end collect()

    /**
     * Query the objects of one schema related to a meeting.
     *
     * @param string $schema      The OpenRegister schema slug
     * @param string $meetingUuid The meeting UUID the objects relate to
     *
     * @return iterable<mixed> The raw OpenRegister results.
     */
    private function relatedObjects(string $schema, string $meetingUuid): iterable
    {
        return $this->objectService()->findAll(
            [
                'filters' => [
                    'register'           => 'decidesk',
                    'schema'             => $schema,
                    '_relations.meeting' => $meetingUuid,
                ],
            ]
        );

    }//end relatedObjects()

    /**
     * Normalise one group of related objects into data + source descriptors.
     *
     * @param iterable<mixed>    $raw       The raw OpenRegister results
     * @param string             $kind      The source kind, e.g. agendaItem
     * @param array<int, string> $labelKeys Candidate label fields, in priority order
     * @param string             $fallback  The fallback label
     *
     * @return array{data: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>}
     */
    private function section(iterable $raw, string $kind, array $labelKeys, string $fallback): array
    {
        $data    = [];
        $sources = [];

        foreach ($raw as $rawItem) {
            $item      = $this->toArray(item: $rawItem);
            $data[]    = $item;
            $sources[] = $this->makeSource(
                kind: $kind,
                uuid: $this->extractUuid(item: $item),
                label: $this->pickLabel(item: $item, keys: $labelKeys, fallback: $fallback)
            );
        }//end foreach

        return [
            'data'    => $data,
            'sources' => $sources,
        ];

    }//end section()
}//end class
