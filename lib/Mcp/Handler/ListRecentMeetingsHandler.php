<?php
/**
 * Decidesk MCP Handler — decidesk.listRecentMeetings
 *
 * Returns the caller's recent meetings ordered by scheduled date descending,
 * scoped to the governance bodies the caller actually participates in.
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
 * Handler for the decidesk.listRecentMeetings MCP tool.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class ListRecentMeetingsHandler extends AbstractToolHandler
{

    /**
     * The status filter values accepted by this tool.
     *
     * @var array<int, string>
     */
    private const VALID_STATUSES = ['any', 'scheduled', 'in-progress', 'closed'];

    /**
     * Handle decidesk.listRecentMeetings.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function handle(array $args): array
    {
        $limit = 10;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        $statusFilter = $args['statusFilter'] ?? 'any';

        $invalid = $this->validateArgs(limit: $limit, statusFilter: $statusFilter);
        if ($invalid !== null) {
            return $invalid;
        }

        try {
            return $this->collect(limit: $limit, statusFilter: (string) $statusFilter);
        } catch (Throwable $error) {
            return $this->internalError(
                tool: 'listRecentMeetings',
                message: 'Failed to retrieve meetings. See server log for details.',
                error: $error
            );
        }//end try

    }//end handle()

    /**
     * Validate the limit and statusFilter arguments.
     *
     * @param int   $limit        The parsed limit argument
     * @param mixed $statusFilter The raw statusFilter argument
     *
     * @return array<string, mixed>|null An error envelope, or null when valid.
     */
    private function validateArgs(int $limit, mixed $statusFilter): ?array
    {
        if ($limit < 1 || $limit > 20) {
            return $this->invalidArguments(
                message: "Invalid limit {$limit}. Must be between 1 and 20."
            );
        }

        if (in_array(needle: $statusFilter, haystack: self::VALID_STATUSES, strict: true) === false) {
            return $this->invalidArguments(
                message: "Invalid statusFilter '{$statusFilter}'. Allowed: "
                    .implode(separator: ', ', array: self::VALID_STATUSES).'.'
            );
        }

        return null;

    }//end validateArgs()

    /**
     * Fetch and shape the caller's recent meetings.
     *
     * @param int    $limit        The validated result limit
     * @param string $statusFilter The validated lifecycle status filter
     *
     * @return array<string, mixed>
     */
    private function collect(int $limit, string $statusFilter): array
    {
        $rawMeetings = $this->objectService()->findAll(
            ['filters' => $this->buildFilters(limit: $limit, statusFilter: $statusFilter)]
        );

        // Scope results to meetings the caller participates in.
        // Admins see all meetings; non-admins see only their own governance bodies.
        // (OWASP A01:2021 — Broken Access Control / ADR-005).
        $currentUserId      = $this->currentUserId();
        $callerMeetingUuids = null;
        if ($currentUserId !== '') {
            $callerMeetingUuids = $this->scopeResolver->callerMeetingUuids(userId: $currentUserId);
        }

        $meetings = [];
        $sources  = [];

        foreach ($rawMeetings as $raw) {
            $meeting     = $this->toArray(item: $raw);
            $meetingUuid = $this->extractUuid(item: $meeting);

            // Filter to the caller's meetings when not an admin.
            if ($callerMeetingUuids !== null
                && in_array($meetingUuid, $callerMeetingUuids, true) === false
            ) {
                continue;
            }

            $meetings[] = $meeting;
            $sources[]  = $this->makeSource(
                kind: 'meeting',
                uuid: $meetingUuid,
                label: $this->pickLabel(item: $meeting, keys: ['title'], fallback: 'Meeting')
            );
        }//end foreach

        return $this->withSources(
            result: [
                'success'  => true,
                'meetings' => $meetings,
            ],
            sources: $sources
        );

    }//end collect()

    /**
     * Build the OpenRegister filter set for the meeting query.
     *
     * @param int    $limit        The validated result limit
     * @param string $statusFilter The validated lifecycle status filter
     *
     * @return array<string, mixed>
     */
    private function buildFilters(int $limit, string $statusFilter): array
    {
        $filters = [
            'register' => 'decidesk',
            'schema'   => 'meeting',
            '_limit'   => $limit,
            '_order'   => ['scheduledDate' => 'DESC'],
        ];

        if ($statusFilter !== 'any') {
            $filters['lifecycle'] = $statusFilter;
        }

        return $filters;

    }//end buildFilters()
}//end class
