<?php
/**
 * Decidesk MCP Handler — decidesk.listOpenActionItems
 *
 * Returns incomplete action items scoped to the caller (scope=mine) or to every
 * meeting the caller legitimately participates in (scope=all).
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
 * Handler for the decidesk.listOpenActionItems MCP tool.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class ListOpenActionItemsHandler extends AbstractToolHandler
{
    /**
     * Handle decidesk.listOpenActionItems.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function handle(array $args): array
    {
        $scope = $args['scope'] ?? 'mine';

        $limit = 20;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        $invalid = $this->validateArgs(scope: $scope, limit: $limit);
        if ($invalid !== null) {
            return $invalid;
        }

        try {
            return $this->collect(scope: (string) $scope, limit: $limit);
        } catch (Throwable $error) {
            return $this->internalError(
                tool: 'listOpenActionItems',
                message: 'Failed to retrieve action items. See server log for details.',
                error: $error
            );
        }//end try

    }//end handle()

    /**
     * Validate the scope and limit arguments.
     *
     * @param mixed $scope The raw scope argument
     * @param int   $limit The parsed limit argument
     *
     * @return array<string, mixed>|null An error envelope, or null when valid.
     */
    private function validateArgs(mixed $scope, int $limit): ?array
    {
        if (in_array(needle: $scope, haystack: ['mine', 'all'], strict: true) === false) {
            return $this->invalidArguments(
                message: "Invalid scope '{$scope}'. Allowed values: mine, all."
            );
        }

        if ($limit < 1 || $limit > 50) {
            return $this->invalidArguments(
                message: "Invalid limit {$limit}. Must be between 1 and 50."
            );
        }

        return null;

    }//end validateArgs()

    /**
     * Fetch and shape the open action items for the caller.
     *
     * @param string $scope The validated scope: mine or all
     * @param int    $limit The validated result limit
     *
     * @return array<string, mixed>
     */
    private function collect(string $scope, int $limit): array
    {
        $currentUserId = $this->currentUserId();

        $rawItems = $this->objectService()->findAll(
            ['filters' => $this->buildFilters(scope: $scope, limit: $limit, userId: $currentUserId)]
        );

        // For scope=all, non-admins see only items linked to meetings they participate in.
        // This prevents cross-governance-body data exposure (OWASP A01 / ADR-005).
        $callerMeetingUuids = null;
        if ($scope === 'all' && $currentUserId !== '') {
            $callerMeetingUuids = $this->scopeResolver->callerMeetingUuids(userId: $currentUserId);
        }

        $items   = [];
        $sources = [];

        foreach ($rawItems as $raw) {
            $item = $this->toArray(item: $raw);

            if ($this->isVisible(item: $item, callerMeetingUuids: $callerMeetingUuids) === false) {
                continue;
            }

            $items[]   = $item;
            $sources[] = $this->makeSource(
                kind: 'actionItem',
                uuid: $this->extractUuid(item: $item),
                label: $this->pickLabel(item: $item, keys: ['title', 'name'], fallback: 'Action item')
            );
        }//end foreach

        return $this->withSources(
            result: [
                'success' => true,
                'items'   => $items,
            ],
            sources: $sources
        );

    }//end collect()

    /**
     * Build the OpenRegister filter set for the action-item query.
     *
     * @param string $scope  The validated scope: mine or all
     * @param int    $limit  The validated result limit
     * @param string $userId The calling user id, or empty string when anonymous
     *
     * @return array<string, mixed>
     */
    private function buildFilters(string $scope, int $limit, string $userId): array
    {
        $filters = [
            'register'  => 'decidesk',
            'schema'    => 'action-item',
            'completed' => false,
            '_limit'    => $limit,
        ];

        if ($scope === 'mine' && $userId !== '') {
            $filters['assignee'] = $userId;
        }

        return $filters;

    }//end buildFilters()

    /**
     * Decide whether an action item may be shown to the caller.
     *
     * A null $callerMeetingUuids means "unrestricted" (admin); otherwise the
     * item's meeting relation must be inside the caller's own set.
     *
     * @param array<string, mixed> $item               The normalised action item
     * @param array<string>|null   $callerMeetingUuids The caller's meeting UUIDs, or null for unrestricted
     *
     * @return bool True when the item may be returned.
     */
    private function isVisible(array $item, ?array $callerMeetingUuids): bool
    {
        if ($callerMeetingUuids === null) {
            return true;
        }

        $itemMeetingId = $this->meetingIdOf(item: $item);
        if ($itemMeetingId === null) {
            return false;
        }

        return in_array($itemMeetingId, $callerMeetingUuids, true);

    }//end isVisible()

    /**
     * Resolve the meeting UUID an action item is attached to.
     *
     * @param array<string, mixed> $item The normalised action item
     *
     * @return string|null The meeting UUID, or null when the item has no meeting relation.
     */
    private function meetingIdOf(array $item): ?string
    {
        $itemMeetingId = $item['@self']['relations']['meeting'] ?? $item['meeting'] ?? null;
        if ($itemMeetingId !== null) {
            return (string) $itemMeetingId;
        }

        // No direct meeting relation — fall back to the generic relations array.
        foreach (($item['relations'] ?? []) as $rel) {
            if (is_array($rel) === true && ($rel['schema'] ?? '') === 'meeting') {
                $relatedId = $rel['id'] ?? null;
                if ($relatedId === null) {
                    return null;
                }

                return (string) $relatedId;
            }
        }

        return null;

    }//end meetingIdOf()
}//end class
