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

use OCA\Decidiq\Service\MeetingService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
			'inputSchema' => [
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
			],
		],
		[
			'id' => 'decidesk.listRecentMeetings',
			'subject' => 'meeting',
			'action' => 'list',
			'name' => 'List recent meetings',
			'description' => 'List the caller\'s recent meetings, ordered by date descending.',
			'inputSchema' => [
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
			],
		],
		[
			'id' => 'decidesk.getMeetingDetails',
			'subject' => 'meeting',
			'action' => 'get',
			'name' => 'Get meeting details',
			'description' => 'Fetch a meeting with agenda items, decisions, and action items inlined.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'meetingUuid' => [
						'type' => 'string',
						'format' => 'uuid',
					],
				],
				'required' => ['meetingUuid'],
			],
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
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'meetingUuid' => [
						'type' => 'string',
						'format' => 'uuid',
					],
				],
				'required' => ['meetingUuid'],
			],
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
					'meetingUuid' => [
						'type' => 'string',
						'format' => 'uuid',
					],
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
	 * Implements the two action-item tools.
	 *
	 * @var McpActionItemTools
	 */
	private readonly McpActionItemTools $actionItemTools;

	/**
	 * Implements the three meeting tools.
	 *
	 * @var McpMeetingTools
	 */
	private readonly McpMeetingTools $meetingTools;

	/**
	 * Constructor for DecidiqToolProvider.
	 *
	 * The two tool collaborators are built in the constructor body rather than
	 * injected, so the DI signature other apps copy stays the six framework
	 * services below.
	 *
	 * @param MeetingService $meetingService The meeting service
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager The group manager (for admin checks)
	 * @param ContainerInterface $container The DI container (McpActionItemTools resolves ActionItemWriter through it)
	 * @param LoggerInterface $logger The PSR-3 logger
	 * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service (ADR-084)
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function __construct(
		MeetingService $meetingService,
		IUserSession $userSession,
		IGroupManager $groupManager,
		ContainerInterface $container,
		LoggerInterface $logger,
		ParticipantResolver $participantResolver,
		ObjectServiceInterface $objectService,
	) {
		$this->actionItemTools = new McpActionItemTools(
			container: $container,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $logger,
			participantResolver: $participantResolver,
			objectService: $objectService,
		);

		$this->meetingTools = new McpMeetingTools(
			meetingService: $meetingService,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $logger,
			participantResolver: $participantResolver,
			objectService: $objectService,
		);

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
