<?php

/**
 * Decidiq MCP Tool Provider
 *
 * First per-app exemplar of OCA\OpenRegister\Mcp\IMcpToolProvider.
 * Exposes 5 MCP tools so the AI Chat Companion (hydra ADR-034) can surface
 * Decidiq capabilities — listing action items and meetings, reading meeting
 * details, starting a meeting, and adding action items — to an LLM.
 *
 * @category Mcp
 * @package  OCA\Decidiq\Mcp
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

namespace OCA\Decidiq\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * Decidiq MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 5 governance tools to the
 * AI Chat Companion. This is the reference implementation other Conduction apps
 * will copy.
 *
 * This class is the dispatcher only: it owns the tool catalogue and routes a
 * tool id to McpActionItemTools or McpMeetingTools, which hold the handlers.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside every handler, AFTER argument
 *   validation but BEFORE business logic. Every helper invoked MUST actually
 *   run. It is centralised in McpMeetingGate::authorise().
 * - McpMeetingGate::isChairOrAdmin() / isParticipantOrAdmin() return bool —
 *   they do NOT return true unconditionally and are NOT wrapped in
 *   catch(\Throwable).
 * - isAdmin() uses IGroupManager::isAdmin() (NC system admin) as the admin gate.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class DecidiqToolProvider implements IMcpToolProvider {

	/**
	 * The meetingUuid argument shared by every tool that acts on one meeting.
	 *
	 * @var array<string, string>
	 */
	private const MEETING_UUID_PROPERTY = [
		'type' => 'string',
		'format' => 'uuid',
	];

	/**
	 * Input schema of decidesk.listOpenActionItems.
	 *
	 * @var array<string, mixed>
	 */
	private const LIST_ACTION_ITEMS_INPUT = [
		'type' => 'object',
		'properties' => [
			'scope' => [
				'type' => 'string',
				'enum' => ['mine', 'all'],
				'default' => 'mine',
			],
			'limit' => [
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 50,
				'default' => 20,
			],
		],
		'required' => [],
	];

	/**
	 * Input schema of decidesk.listRecentMeetings.
	 *
	 * @var array<string, mixed>
	 */
	private const RECENT_MEETINGS_INPUT = [
		'type' => 'object',
		'properties' => [
			'limit' => [
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 20,
				'default' => 10,
			],
			'statusFilter' => [
				'type' => 'string',
				'enum' => ['any', 'scheduled', 'in-progress', 'closed'],
				'default' => 'any',
			],
		],
		'required' => [],
	];

	/**
	 * Input schema of the tools that take only a meeting.
	 *
	 * @var array<string, mixed>
	 */
	private const MEETING_ONLY_INPUT = [
		'type' => 'object',
		'properties' => [
			'meetingUuid' => self::MEETING_UUID_PROPERTY,
		],
		'required' => ['meetingUuid'],
	];

	/**
	 * Tool catalogue (REQ-DMCP-002).
	 *
	 * Hard-coded as a constant so unit tests can assert it as a fixture.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const TOOL_DESCRIPTORS = [
		[
			'id' => 'decidesk.listOpenActionItems',
			'subject' => 'actionItem',
			'action' => 'list',
			'name' => 'List open action items',
			'description' => 'List incomplete action items assigned to you (scope=mine) or all visible (scope=all).',
			'inputSchema' => self::LIST_ACTION_ITEMS_INPUT,
		],
		[
			'id' => 'decidesk.listRecentMeetings',
			'subject' => 'meeting',
			'action' => 'list',
			'name' => 'List recent meetings',
			'description' => 'List the caller\'s recent meetings, ordered by date descending.',
			'inputSchema' => self::RECENT_MEETINGS_INPUT,
		],
		[
			'id' => 'decidesk.getMeetingDetails',
			'subject' => 'meeting',
			'action' => 'get',
			'name' => 'Get meeting details',
			'description' => 'Fetch a meeting with agenda items, decisions, and action items inlined.',
			'inputSchema' => self::MEETING_ONLY_INPUT,
		],
		[
			'id' => 'decidesk.startMeeting',
			// `start`, not `create`: it moves an existing meeting through a
			// lifecycle transition, which is a different authority from
			// bringing a meeting into existence.
			'subject' => 'meeting',
			'action' => 'start',
			'name' => 'Start meeting',
			'description' => 'Transition a scheduled meeting to in-progress. Chair or admin only.',
			'inputSchema' => self::MEETING_ONLY_INPUT,
		],
		[
			'id' => 'decidesk.addActionItem',
			'subject' => 'actionItem',
			'action' => 'create',
			'name' => 'Add action item',
			'description' => 'Create an action item attached to a meeting. Participant or admin only.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'meetingUuid' => self::MEETING_UUID_PROPERTY,
					'title' => [
						'type' => 'string',
						'minLength' => 3,
						'maxLength' => 200,
					],
					'assigneeUserId' => [
						'type' => ['string', 'null'],
						'default' => null,
					],
					'dueDate' => [
						'type' => ['string', 'null'],
						'format' => 'date',
						'default' => null,
					],
				],
				'required' => ['meetingUuid', 'title'],
			],
		],
	];

	/**
	 * Constructor for DecidiqToolProvider.
	 *
	 * @param McpActionItemTools $actionItemTools Implements the two action-item tools
	 * @param McpMeetingTools    $meetingTools    Implements the three meeting tools
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function __construct(
		private readonly McpActionItemTools $actionItemTools,
		private readonly McpMeetingTools $meetingTools,
	) {
	}//end __construct()

	/**
	 * Returns the app ID that namespaces every tool id.
	 *
	 * @return string "decidesk"
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function getAppId(): string {
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
	public function getTools(): array {
		return self::TOOL_DESCRIPTORS;
	}//end getTools()

	/**
	 * Dispatch a tool call by id.
	 *
	 * Argument validation runs BEFORE authorisation (cheap before expensive),
	 * which runs BEFORE state checks, which run BEFORE business logic (design D4).
	 * Unknown tool ids return a structured error; no exception is thrown.
	 *
	 * @param string $toolId The tool id (e.g. "decidesk.startMeeting")
	 * @param array<string, mixed> $arguments Tool arguments from the LLM call
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		return match ($toolId) {
			'decidesk.listOpenActionItems' => $this->actionItemTools->listOpenActionItems(args: $arguments),
			'decidesk.listRecentMeetings' => $this->meetingTools->listRecentMeetings(args: $arguments),
			'decidesk.getMeetingDetails' => $this->meetingTools->getMeetingDetails(args: $arguments),
			'decidesk.startMeeting' => $this->meetingTools->startMeeting(args: $arguments),
			'decidesk.addActionItem' => $this->actionItemTools->addActionItem(args: $arguments),
			default => [
				'isError' => true,
				'error' => 'unknown_tool',
				'message' => "Unknown tool id '{$toolId}'. Available tools: "
					. implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')) . '.',
			],
		};

	}//end invokeTool()
}//end class
