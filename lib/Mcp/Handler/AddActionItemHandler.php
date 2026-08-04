<?php
/**
 * Decidesk MCP Handler — decidesk.addActionItem
 *
 * Creates an action item attached to a meeting, after checking that the caller
 * is a participant of that meeting or an admin.
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

use OCA\Decidesk\Service\ActionItemWriter;
use Throwable;

/**
 * Handler for the decidesk.addActionItem MCP tool.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class AddActionItemHandler extends AbstractToolHandler
{
    /**
     * Handle decidesk.addActionItem.
     *
     * Argument validation runs BEFORE authorisation, which runs BEFORE the
     * write (design D4).
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function handle(array $args): array
    {
        $invalid = $this->validateArgs(args: $args);
        if ($invalid !== null) {
            return $invalid;
        }

        $meetingUuid = (string) $args['meetingUuid'];
        $title       = (string) $args['title'];

        $meeting = $this->loadMeeting(meetingUuid: $meetingUuid);
        if ($meeting === null) {
            return $this->meetingNotFound();
        }

        $isParticipantOrAdmin = $this->requireParticipantOrAdmin(
            meetingUuid: $meetingUuid,
            userId: $this->currentUserId(),
        );
        if ($isParticipantOrAdmin === false) {
            return $this->notAParticipant();
        }

        try {
            return $this->create(
                itemData: $this->buildItemData(args: $args, meetingUuid: $meetingUuid, title: $title),
                meetingUuid: $meetingUuid,
                meeting: $meeting,
                title: $title
            );
        } catch (Throwable $error) {
            return $this->internalError(
                tool: 'addActionItem',
                message: 'Failed to create action item. See server log for details.',
                error: $error,
                context: ['meetingUuid' => $meetingUuid]
            );
        }//end try

    }//end handle()

    /**
     * Validate the meetingUuid, title and dueDate arguments, in that order.
     *
     * @param array<string, mixed> $args The raw tool arguments
     *
     * @return array<string, mixed>|null An error envelope, or null when valid.
     */
    private function validateArgs(array $args): ?array
    {
        $invalidUuid = $this->validateMeetingUuid(meetingUuid: ($args['meetingUuid'] ?? null));
        if ($invalidUuid !== null) {
            return $invalidUuid;
        }

        $invalidTitle = $this->validateTitle(title: ($args['title'] ?? null));
        if ($invalidTitle !== null) {
            return $invalidTitle;
        }

        return $this->validateDueDate(dueDate: ($args['dueDate'] ?? null));

    }//end validateArgs()

    /**
     * Validate the title argument.
     *
     * @param mixed $title The raw title argument
     *
     * @return array<string, mixed>|null An error envelope, or null when valid.
     */
    private function validateTitle(mixed $title): ?array
    {
        if ($title === null || $title === '') {
            return $this->invalidArguments(message: 'Required argument title is missing.');
        }

        $titleLen = mb_strlen((string) $title);
        if ($titleLen < 3 || $titleLen > 200) {
            return $this->invalidArguments(
                message: "Title must be between 3 and 200 characters (got {$titleLen})."
            );
        }

        return null;

    }//end validateTitle()

    /**
     * Validate the optional dueDate argument.
     *
     * @param mixed $dueDate The raw dueDate argument
     *
     * @return array<string, mixed>|null An error envelope, or null when valid or absent.
     */
    private function validateDueDate(mixed $dueDate): ?array
    {
        if ($dueDate === null || $dueDate === '') {
            return null;
        }

        if ($this->isValidDate(candidate: (string) $dueDate) === false) {
            return $this->invalidArguments(
                message: "Invalid dueDate '{$dueDate}'. Expected ISO 8601 date (YYYY-MM-DD)."
            );
        }

        return null;

    }//end validateDueDate()

    /**
     * Validate that a string is a valid ISO 8601 date (YYYY-MM-DD).
     *
     * @param string $candidate The candidate string
     *
     * @return bool True when the string is a valid date.
     */
    private function isValidDate(string $candidate): bool
    {
        $dateObj = date_create_from_format('Y-m-d', $candidate);
        return $dateObj !== false && date_format($dateObj, 'Y-m-d') === $candidate;

    }//end isValidDate()

    /**
     * Build the ActionItem payload written to the CalDAV VTODO store.
     *
     * Writes the canonical CalDAV VTODO ActionItem (ADR-002 source of truth);
     * the Deck integration leaf renders it as a board card (ADR-019 /
     * migrate-action-items-to-deck-leaf). The retired in-app TaskService no
     * longer mediates this write.
     *
     * @param array<string, mixed> $args        The validated tool arguments
     * @param string               $meetingUuid The meeting the item attaches to
     * @param string               $title       The validated action item title
     *
     * @return array<string, mixed>
     */
    private function buildItemData(array $args, string $meetingUuid, string $title): array
    {
        $actionItemData = [
            'title'      => $title,
            'taskStatus' => 'open',
            'relations'  => ['Meeting' => [$meetingUuid]],
        ];

        $assigneeUserId = $args['assigneeUserId'] ?? null;
        if ($assigneeUserId !== null && $assigneeUserId !== '') {
            $actionItemData['assignee'] = (string) $assigneeUserId;
        }

        $dueDate = $args['dueDate'] ?? null;
        if ($dueDate !== null && $dueDate !== '') {
            $actionItemData['dueDate'] = (string) $dueDate;
        }

        return $actionItemData;

    }//end buildItemData()

    /**
     * Write the action item and shape the success payload.
     *
     * The action-item schema is a read-only VTODO projection, so the item is
     * created via ActionItemWriter (TaskService) rather than
     * ObjectService::saveObject.
     *
     * @param array<string, mixed> $itemData    The ActionItem payload
     * @param string               $meetingUuid The meeting the item attaches to
     * @param array<string, mixed> $meeting     The loaded meeting object
     * @param string               $title       The validated action item title
     *
     * @return array<string, mixed>
     */
    private function create(array $itemData, string $meetingUuid, array $meeting, string $title): array
    {
        $writer = $this->container->get(ActionItemWriter::class);
        $saved  = $writer->create(item: $itemData);
        if ($saved === null) {
            return $this->errorResult(
                error: 'create_failed',
                message: 'Could not create the action item.'
            );
        }

        $itemUuid = (string) ($saved['uid'] ?? $saved['id'] ?? '');

        return [
            'success'    => true,
            'created'    => true,
            'actionItem' => $saved,
            'sources'    => [
                $this->makeSource(kind: 'actionItem', uuid: $itemUuid, label: $title),
                $this->makeSource(
                    kind: 'meeting',
                    uuid: $meetingUuid,
                    label: $this->pickLabel(item: $meeting, keys: ['title'], fallback: 'Meeting')
                ),
            ],
        ];

    }//end create()
}//end class
