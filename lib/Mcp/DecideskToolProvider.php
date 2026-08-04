<?php
/**
 * Decidesk MCP Tool Provider
 *
 * First per-app exemplar of OCA\OpenRegister\Mcp\IMcpToolProvider.
 * Exposes 5 MCP tools so the AI Chat Companion (hydra ADR-034) can surface
 * decidesk capabilities — listing action items and meetings, reading meeting
 * details, starting a meeting, and adding action items — to an LLM.
 *
 * @category Mcp
 * @package  OCA\Decidesk\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Mcp;

use OCA\Decidesk\Mcp\Handler\AddActionItemHandler;
use OCA\Decidesk\Mcp\Handler\GetMeetingDetailsHandler;
use OCA\Decidesk\Mcp\Handler\ListOpenActionItemsHandler;
use OCA\Decidesk\Mcp\Handler\ListRecentMeetingsHandler;
use OCA\Decidesk\Mcp\Handler\StartMeetingHandler;
use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * Decidesk MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 5 governance tools to the
 * AI Chat Companion. This is the reference implementation other Conduction apps
 * will copy.
 *
 * This class is a registry/dispatcher only: it owns the tool catalogue and
 * routes a tool id to the handler that implements it. All argument validation,
 * authorisation and response shaping live in the per-tool handlers under
 * OCA\Decidesk\Mcp\Handler, which share OCA\Decidesk\Mcp\Handler\AbstractToolHandler.
 *
 * Auth design (OWASP A01:2021 / ADR-005) is documented on AbstractToolHandler:
 * per-object authorisation runs inside each handler, AFTER argument validation
 * but BEFORE business logic.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class DecideskToolProvider implements IMcpToolProvider
{

    /**
     * Tool catalogue (REQ-DMCP-002).
     *
     * Hard-coded as a constant so unit tests can assert it as a fixture.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'decidesk.listOpenActionItems',
            'name'        => 'List open action items',
            'description' => 'List incomplete action items assigned to you (scope=mine) or all visible (scope=all).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'scope' => [
                        'type'    => 'string',
                        'enum'    => ['mine', 'all'],
                        'default' => 'mine',
                    ],
                    'limit' => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'decidesk.listRecentMeetings',
            'name'        => 'List recent meetings',
            'description' => 'List the caller\'s recent meetings, ordered by date descending.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'        => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 20,
                        'default' => 10,
                    ],
                    'statusFilter' => [
                        'type'    => 'string',
                        'enum'    => ['any', 'scheduled', 'in-progress', 'closed'],
                        'default' => 'any',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'decidesk.getMeetingDetails',
            'name'        => 'Get meeting details',
            'description' => 'Fetch a meeting with agenda items, decisions, and action items inlined.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'meetingUuid' => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
                'required'   => ['meetingUuid'],
            ],
        ],
        [
            'id'          => 'decidesk.startMeeting',
            'name'        => 'Start meeting',
            'description' => 'Transition a scheduled meeting to in-progress. Chair or admin only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'meetingUuid' => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
                'required'   => ['meetingUuid'],
            ],
        ],
        [
            'id'          => 'decidesk.addActionItem',
            'name'        => 'Add action item',
            'description' => 'Create an action item attached to a meeting. Participant or admin only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'meetingUuid'    => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                    'title'          => [
                        'type'      => 'string',
                        'minLength' => 3,
                        'maxLength' => 200,
                    ],
                    'assigneeUserId' => [
                        'type'    => ['string', 'null'],
                        'default' => null,
                    ],
                    'dueDate'        => [
                        'type'    => ['string', 'null'],
                        'format'  => 'date',
                        'default' => null,
                    ],
                ],
                'required'   => ['meetingUuid', 'title'],
            ],
        ],
    ];

    /**
     * Constructor for DecideskToolProvider.
     *
     * All five handlers are autowired by Nextcloud; the provider itself holds
     * no service dependencies of its own.
     *
     * @param ListOpenActionItemsHandler $listOpenActionItems Handler for decidesk.listOpenActionItems
     * @param ListRecentMeetingsHandler  $listRecentMeetings  Handler for decidesk.listRecentMeetings
     * @param GetMeetingDetailsHandler   $getMeetingDetails   Handler for decidesk.getMeetingDetails
     * @param StartMeetingHandler        $startMeeting        Handler for decidesk.startMeeting
     * @param AddActionItemHandler       $addActionItem       Handler for decidesk.addActionItem
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function __construct(
        private readonly ListOpenActionItemsHandler $listOpenActionItems,
        private readonly ListRecentMeetingsHandler $listRecentMeetings,
        private readonly GetMeetingDetailsHandler $getMeetingDetails,
        private readonly StartMeetingHandler $startMeeting,
        private readonly AddActionItemHandler $addActionItem,
    ) {

    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "decidesk"
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function getAppId(): string
    {
        return 'decidesk';

    }//end getAppId()

    /**
     * Returns the full tool catalogue (5 tools, always).
     *
     * The full catalogue is always returned regardless of caller permissions.
     * Per-object authorisation runs in invokeTool() (REQ-DMCP-004, design D2).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Each handler validates arguments BEFORE authorising (cheap before
     * expensive), which runs BEFORE state checks, which run BEFORE business
     * logic (design D4). Unknown tool ids return a structured error; no
     * exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "decidesk.startMeeting")
     * @param array<string, mixed> $arguments Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        return match ($toolId) {
            'decidesk.listOpenActionItems' => $this->listOpenActionItems->handle(args: $arguments),
            'decidesk.listRecentMeetings'  => $this->listRecentMeetings->handle(args: $arguments),
            'decidesk.getMeetingDetails'   => $this->getMeetingDetails->handle(args: $arguments),
            'decidesk.startMeeting'        => $this->startMeeting->handle(args: $arguments),
            'decidesk.addActionItem'       => $this->addActionItem->handle(args: $arguments),
            default                        => [
                'isError' => true,
                'error'   => 'unknown_tool',
                'message' => "Unknown tool id '{$toolId}'. Available tools: "
                    .implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')).'.',
            ],
        };

    }//end invokeTool()
}//end class
