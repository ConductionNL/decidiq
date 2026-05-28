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
 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Mcp;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\TaskService;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decidesk MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 5 governance tools to the
 * AI Chat Companion. This is the reference implementation other Conduction apps
 * will copy.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside invokeTool(), AFTER argument validation
 *   but BEFORE business logic. Every helper invoked MUST actually run.
 * - requireChairOrAdmin() / requireParticipantOrAdmin() return bool — they do
 *   NOT return true unconditionally and are NOT wrapped in catch(\Throwable).
 * - isAdmin() uses IGroupManager::isAdmin() (NC system admin) as the admin gate.
 *
 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-001
 */
class DecideskToolProvider implements IMcpToolProvider
{

    /**
     * Maximum number of source descriptors per tool result (REQ-DMCP-006).
     *
     * @var int
     */
    private const SOURCES_CAP = 20;

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
     * @param MeetingService      $meetingService      The meeting service
     * @param TaskService         $taskService         The task service
     * @param IUserSession        $userSession         The current user session
     * @param IGroupManager       $groupManager        The group manager (for admin checks)
     * @param ContainerInterface  $container           The DI container (for ObjectService)
     * @param LoggerInterface     $logger              The PSR-3 logger
     * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-008
     */
    public function __construct(
        private readonly MeetingService $meetingService,
        private readonly TaskService $taskService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ParticipantResolver $participantResolver,
    ) {
    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "decidesk"
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-001
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
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-002
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Argument validation runs BEFORE authorisation (cheap before expensive),
     * which runs BEFORE state checks, which run BEFORE business logic (design D4).
     * Unknown tool ids return a structured error; no exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "decidesk.startMeeting")
     * @param array<string, mixed> $arguments Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-003
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        return match ($toolId) {
            'decidesk.listOpenActionItems' => $this->handleListOpenActionItems(args: $arguments),
            'decidesk.listRecentMeetings'  => $this->handleListRecentMeetings(args: $arguments),
            'decidesk.getMeetingDetails'   => $this->handleGetMeetingDetails(args: $arguments),
            'decidesk.startMeeting'        => $this->handleStartMeeting(args: $arguments),
            'decidesk.addActionItem'       => $this->handleAddActionItem(args: $arguments),
            default                        => [
                'isError' => true,
                'error'   => 'unknown_tool',
                'message' => "Unknown tool id '{$toolId}'. Available tools: "
                    .implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')).'.',
            ],
        };

    }//end invokeTool()

    // =========================================================================
    // Private tool handlers
    // =========================================================================

    /**
     * Handle decidesk.listOpenActionItems.
     *
     * Returns incomplete action items scoped to mine or all visible.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-008
     */
    private function handleListOpenActionItems(array $args): array
    {
        $scope = $args['scope'] ?? 'mine';
        $limit = 20;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        if (in_array(needle: $scope, haystack: ['mine', 'all'], strict: true) === false) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid scope '{$scope}'. Allowed values: mine, all.",
            ];
        }

        if ($limit < 1 || $limit > 50) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid limit {$limit}. Must be between 1 and 50.",
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $filters = [
                'register'  => 'decidesk',
                'schema'    => 'action-item',
                'completed' => false,
                '_limit'    => $limit,
            ];

            $currentUserId = $this->userSession->getUser()?->getUID() ?? '';
            if ($scope === 'mine') {
                if ($currentUserId !== '') {
                    $filters['assignee'] = $currentUserId;
                }
            }

            $rawItems = $objectService->findAll(['filters' => $filters]);

            // For scope=all, non-admins see only items linked to meetings they participate in.
            // This prevents cross-governance-body data exposure (OWASP A01 / ADR-005).
            $callerMeetingUuids = null;
            if ($scope === 'all' && $currentUserId !== '') {
                $callerMeetingUuids = $this->getCallerMeetingUuids(userId: $currentUserId);
            }

            $items   = [];
            $sources = [];

            foreach ($rawItems as $raw) {
                $item = $this->toArray(item: $raw);

                // When meeting UUIDs are restricted, only include action items whose
                // meeting relation is in the caller's set.
                if ($callerMeetingUuids !== null) {
                    $itemMeetingId = $item['@self']['relations']['meeting'] ?? $item['meeting'] ?? null;

                    if ($itemMeetingId === null) {
                        // Action item has no meeting relation — check relations array.
                        foreach (($item['relations'] ?? []) as $rel) {
                            if (is_array($rel) === true && ($rel['schema'] ?? '') === 'meeting') {
                                $itemMeetingId = $rel['id'] ?? null;
                                break;
                            }
                        }
                    }

                    if ($itemMeetingId === null || in_array((string) $itemMeetingId, $callerMeetingUuids, true) === false) {
                        continue;
                    }
                }

                $itemUuid = $this->extractUuid(item: $item);
                $title    = (string) ($item['title'] ?? $item['name'] ?? 'Action item');

                $items[]   = $item;
                $sources[] = [
                    'type'  => 'decidesk.actionItem',
                    'uuid'  => $itemUuid,
                    'url'   => $this->buildDeepLink(type: 'actionItem', uuid: $itemUuid),
                    'label' => $title,
                ];
            }//end foreach

            $truncated = $this->truncateSources(sources: $sources);

            $result = [
                'success' => true,
                'items'   => $items,
                'sources' => $truncated['truncated'],
            ];

            if ($truncated['didTruncate'] === true) {
                $result['sourcesTruncated']  = true;
                $result['sourcesTotalCount'] = $truncated['totalCount'];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk MCP: listOpenActionItems failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => 'Failed to retrieve action items. See server log for details.',
            ];
        }//end try

    }//end handleListOpenActionItems()

    /**
     * Handle decidesk.listRecentMeetings.
     *
     * Returns recent meetings ordered by date descending.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-008
     */
    private function handleListRecentMeetings(array $args): array
    {
        $limit = 10;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        $statusFilter = $args['statusFilter'] ?? 'any';

        if ($limit < 1 || $limit > 20) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid limit {$limit}. Must be between 1 and 20.",
            ];
        }

        $validStatuses = ['any', 'scheduled', 'in-progress', 'closed'];
        if (in_array(needle: $statusFilter, haystack: $validStatuses, strict: true) === false) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid statusFilter '{$statusFilter}'. Allowed: "
                    .implode(separator: ', ', array: $validStatuses).'.',
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $filters = [
                'register' => 'decidesk',
                'schema'   => 'meeting',
                '_limit'   => $limit,
                '_order'   => ['scheduledDate' => 'DESC'],
            ];

            if ($statusFilter !== 'any') {
                $filters['lifecycle'] = $statusFilter;
            }

            $rawMeetings = $objectService->findAll(['filters' => $filters]);

            // Scope results to meetings the caller participates in.
            // Admins see all meetings; non-admins see only their own governance bodies.
            // (OWASP A01:2021 — Broken Access Control / ADR-005).
            $currentUserId      = $this->userSession->getUser()?->getUID() ?? '';
            $callerMeetingUuids = null;
            if ($currentUserId !== '') {
                $callerMeetingUuids = $this->getCallerMeetingUuids(userId: $currentUserId);
            }

            $meetings = [];
            $sources  = [];

            foreach ($rawMeetings as $raw) {
                $meeting     = $this->toArray(item: $raw);
                $meetingUuid = $this->extractUuid(item: $meeting);

                // Filter to caller's meetings when not an admin.
                if ($callerMeetingUuids !== null
                    && in_array($meetingUuid, $callerMeetingUuids, true) === false
                ) {
                    continue;
                }

                $title = (string) ($meeting['title'] ?? 'Meeting');

                $meetings[] = $meeting;
                $sources[]  = [
                    'type'  => 'decidesk.meeting',
                    'uuid'  => $meetingUuid,
                    'url'   => $this->buildDeepLink(type: 'meeting', uuid: $meetingUuid),
                    'label' => $title,
                ];
            }//end foreach

            $truncated = $this->truncateSources(sources: $sources);

            $result = [
                'success'  => true,
                'meetings' => $meetings,
                'sources'  => $truncated['truncated'],
            ];

            if ($truncated['didTruncate'] === true) {
                $result['sourcesTruncated']  = true;
                $result['sourcesTotalCount'] = $truncated['totalCount'];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk MCP: listRecentMeetings failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => 'Failed to retrieve meetings. See server log for details.',
            ];
        }//end try

    }//end handleListRecentMeetings()

    /**
     * Handle decidesk.getMeetingDetails.
     *
     * Fetches a meeting with agenda items, decisions, and action items inlined.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-003
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
     */
    private function handleGetMeetingDetails(array $args): array
    {
        $meetingUuid = $args['meetingUuid'] ?? null;

        if ($meetingUuid === null || $meetingUuid === '') {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => 'Required argument meetingUuid is missing.',
            ];
        }

        if ($this->isValidUuid(candidate: (string) $meetingUuid) === false) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid UUID format for meetingUuid: '{$meetingUuid}'.",
            ];
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $meetingEntity = $objectService->find(id: (string) $meetingUuid, register: 'decidesk', schema: 'meeting');
        if ($meetingEntity !== null) {
            $meeting = $meetingEntity->jsonSerialize();
        } else {
            $meeting = null;
        }

        if ($meeting === null) {
            return [
                'isError' => true,
                'error'   => 'not_found',
                'message' => 'Meeting not found.',
            ];
        }

        $currentUserId = '';
        $user          = $this->userSession->getUser();
        if ($user !== null) {
            $currentUserId = $user->getUID();
        }

        $isAuthorised = $this->requireParticipantOrAdmin(
            meetingUuid: (string) $meetingUuid,
            meeting: $meeting,
            userId: $currentUserId,
        );
        if ($isAuthorised === false) {
            return [
                'isError' => true,
                'error'   => 'forbidden',
                'message' => 'You are not a participant of this meeting.',
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $agendaItems = $objectService->findAll(
                [
                    'filters' => [
                        'register'          => 'decidesk',
                        'schema'            => 'agenda-item',
                        'relations.meeting' => $meetingUuid,
                    ],
                ]
            );

            $decisions = $objectService->findAll(
                [
                    'filters' => [
                        'register'          => 'decidesk',
                        'schema'            => 'decision',
                        'relations.meeting' => $meetingUuid,
                    ],
                ]
            );

            $actionItems = $objectService->findAll(
                [
                    'filters' => [
                        'register'          => 'decidesk',
                        'schema'            => 'action-item',
                        'relations.meeting' => $meetingUuid,
                    ],
                ]
            );

            $sources = [];

            // Meeting itself.
            $sources[] = [
                'type'  => 'decidesk.meeting',
                'uuid'  => (string) $meetingUuid,
                'url'   => $this->buildDeepLink(type: 'meeting', uuid: (string) $meetingUuid),
                'label' => (string) ($meeting['title'] ?? 'Meeting'),
            ];

            // Agenda items.
            $agendaData = [];
            foreach ($agendaItems as $raw) {
                $item         = $this->toArray(item: $raw);
                $agendaData[] = $item;
                $itemUuid     = $this->extractUuid(item: $item);
                $sources[]    = [
                    'type'  => 'decidesk.agendaItem',
                    'uuid'  => $itemUuid,
                    'url'   => $this->buildDeepLink(type: 'agendaItem', uuid: $itemUuid),
                    'label' => (string) ($item['title'] ?? $item['subject'] ?? 'Agenda item'),
                ];
            }//end foreach

            // Decisions.
            $decisionData = [];
            foreach ($decisions as $raw) {
                $item           = $this->toArray(item: $raw);
                $decisionData[] = $item;
                $itemUuid       = $this->extractUuid(item: $item);
                $sources[]      = [
                    'type'  => 'decidesk.decision',
                    'uuid'  => $itemUuid,
                    'url'   => $this->buildDeepLink(type: 'decision', uuid: $itemUuid),
                    'label' => (string) ($item['title'] ?? $item['text'] ?? 'Decision'),
                ];
            }//end foreach

            // Action items.
            $actionData = [];
            foreach ($actionItems as $raw) {
                $item         = $this->toArray(item: $raw);
                $actionData[] = $item;
                $itemUuid     = $this->extractUuid(item: $item);
                $sources[]    = [
                    'type'  => 'decidesk.actionItem',
                    'uuid'  => $itemUuid,
                    'url'   => $this->buildDeepLink(type: 'actionItem', uuid: $itemUuid),
                    'label' => (string) ($item['title'] ?? $item['name'] ?? 'Action item'),
                ];
            }//end foreach

            $truncated = $this->truncateSources(sources: $sources);

            $result = [
                'success'     => true,
                'meeting'     => $meeting,
                'agendaItems' => $agendaData,
                'decisions'   => $decisionData,
                'actionItems' => $actionData,
                'sources'     => $truncated['truncated'],
            ];

            if ($truncated['didTruncate'] === true) {
                $result['sourcesTruncated']  = true;
                $result['sourcesTotalCount'] = $truncated['totalCount'];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk MCP: getMeetingDetails failed',
                ['meetingUuid' => $meetingUuid, 'exception' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => 'Failed to retrieve meeting details. See server log for details.',
            ];
        }//end try

    }//end handleGetMeetingDetails()

    /**
     * Handle decidesk.startMeeting.
     *
     * Transitions a scheduled meeting to opened (in-progress).
     * Only the chair or an admin may do this.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-005
     */
    private function handleStartMeeting(array $args): array
    {
        $meetingUuid = $args['meetingUuid'] ?? null;

        if ($meetingUuid === null || $meetingUuid === '') {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => 'Required argument meetingUuid is missing.',
            ];
        }

        if ($this->isValidUuid(candidate: (string) $meetingUuid) === false) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid UUID format for meetingUuid: '{$meetingUuid}'.",
            ];
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $meetingEntity = $objectService->find(id: (string) $meetingUuid, register: 'decidesk', schema: 'meeting');
        if ($meetingEntity !== null) {
            $meeting = $meetingEntity->jsonSerialize();
        } else {
            $meeting = null;
        }

        if ($meeting === null) {
            return [
                'isError' => true,
                'error'   => 'not_found',
                'message' => 'Meeting not found.',
            ];
        }

        $currentUserId = '';
        $user          = $this->userSession->getUser();
        if ($user !== null) {
            $currentUserId = $user->getUID();
        }

        $isChairOrAdmin = $this->requireChairOrAdmin(
            meetingUuid: (string) $meetingUuid,
            meeting: $meeting,
            userId: $currentUserId,
        );
        if ($isChairOrAdmin === false) {
            return [
                'isError' => true,
                'error'   => 'forbidden',
                'message' => 'Only the chair or an admin can start this meeting.',
            ];
        }

        // State guard: only scheduled meetings can be opened (REQ-DMCP-005).
        $lifecycle  = $meeting['lifecycle'] ?? 'draft';
        $stateLabel = $lifecycle;
        if ($lifecycle === 'opened') {
            $stateLabel = 'in progress';
        }

        if ($lifecycle !== 'scheduled') {
            return [
                'isError' => true,
                'error'   => 'invalid_state',
                'message' => "Meeting is already {$stateLabel}.",
            ];
        }

        $result = $this->meetingService->transition(
            meetingId: (string) $meetingUuid,
            action: 'open',
            currentUserId: $currentUserId,
        );

        if ($result['success'] === false) {
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => $result['message'],
            ];
        }

        $startedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return [
            'success'     => true,
            'started'     => true,
            'meetingUuid' => (string) $meetingUuid,
            'startedAt'   => $startedAt,
            'sources'     => [
                [
                    'type'  => 'decidesk.meeting',
                    'uuid'  => (string) $meetingUuid,
                    'url'   => $this->buildDeepLink(type: 'meeting', uuid: (string) $meetingUuid),
                    'label' => (string) ($meeting['title'] ?? 'Meeting'),
                ],
            ],
        ];

    }//end handleStartMeeting()

    /**
     * Handle decidesk.addActionItem.
     *
     * Creates an action item attached to a meeting.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-008
     */
    private function handleAddActionItem(array $args): array
    {
        $meetingUuid = $args['meetingUuid'] ?? null;
        $title       = $args['title'] ?? null;

        if ($meetingUuid === null || $meetingUuid === '') {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => 'Required argument meetingUuid is missing.',
            ];
        }

        if ($this->isValidUuid(candidate: (string) $meetingUuid) === false) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Invalid UUID format for meetingUuid: '{$meetingUuid}'.",
            ];
        }

        if ($title === null || $title === '') {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => 'Required argument title is missing.',
            ];
        }

        $titleLen = mb_strlen((string) $title);
        if ($titleLen < 3 || $titleLen > 200) {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => "Title must be between 3 and 200 characters (got {$titleLen}).",
            ];
        }

        $dueDate = $args['dueDate'] ?? null;
        if ($dueDate !== null && $dueDate !== '') {
            if ($this->isValidDate(candidate: (string) $dueDate) === false) {
                return [
                    'isError' => true,
                    'error'   => 'invalid_arguments',
                    'message' => "Invalid dueDate '{$dueDate}'. Expected ISO 8601 date (YYYY-MM-DD).",
                ];
            }
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $meetingEntity = $objectService->find(id: (string) $meetingUuid, register: 'decidesk', schema: 'meeting');
        if ($meetingEntity !== null) {
            $meeting = $meetingEntity->jsonSerialize();
        } else {
            $meeting = null;
        }

        if ($meeting === null) {
            return [
                'isError' => true,
                'error'   => 'not_found',
                'message' => 'Meeting not found.',
            ];
        }

        $currentUserId = '';
        $user          = $this->userSession->getUser();
        if ($user !== null) {
            $currentUserId = $user->getUID();
        }

        $isParticipantOrAdmin = $this->requireParticipantOrAdmin(
            meetingUuid: (string) $meetingUuid,
            meeting: $meeting,
            userId: $currentUserId,
        );
        if ($isParticipantOrAdmin === false) {
            return [
                'isError' => true,
                'error'   => 'forbidden',
                'message' => 'You are not a participant of this meeting.',
            ];
        }

        $taskData = [
            'title'      => (string) $title,
            'meeting'    => (string) $meetingUuid,
            'taskStatus' => 'pending',
            'createdBy'  => $currentUserId,
        ];

        $assigneeUserId = $args['assigneeUserId'] ?? null;
        if ($assigneeUserId !== null && $assigneeUserId !== '') {
            $taskData['assignee'] = (string) $assigneeUserId;
        }

        if ($dueDate !== null && $dueDate !== '') {
            $taskData['dueDate'] = (string) $dueDate;
        }

        try {
            $saved    = $this->taskService->saveTask($taskData);
            $itemUuid = $this->extractUuid(item: $saved);

            return [
                'success'    => true,
                'created'    => true,
                'actionItem' => $saved,
                'sources'    => [
                    [
                        'type'  => 'decidesk.actionItem',
                        'uuid'  => $itemUuid,
                        'url'   => $this->buildDeepLink(type: 'actionItem', uuid: $itemUuid),
                        'label' => (string) $title,
                    ],
                    [
                        'type'  => 'decidesk.meeting',
                        'uuid'  => (string) $meetingUuid,
                        'url'   => $this->buildDeepLink(type: 'meeting', uuid: (string) $meetingUuid),
                        'label' => (string) ($meeting['title'] ?? 'Meeting'),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk MCP: addActionItem failed',
                ['meetingUuid' => $meetingUuid, 'exception' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => 'Failed to create action item. See server log for details.',
            ];
        }//end try

    }//end handleAddActionItem()

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Validate that a string is a syntactically valid UUID (8-4-4-4-12 hex).
     *
     * @param string $candidate The candidate string to validate
     *
     * @return bool True when the string is UUID-shaped.
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
     */
    private function isValidUuid(string $candidate): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $candidate
        );

    }//end isValidUuid()

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
     * Check whether the calling user is the meeting chair or a system admin.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - The chair is identified by the 'chair' field in the meeting object.
     * - Admin is resolved via IGroupManager::isAdmin() (NC system admin group).
     * - This helper MUST actually run — it does not return true unconditionally.
     *
     * @param string               $meetingUuid The meeting UUID (for context)
     * @param array<string, mixed> $meeting     The meeting data array
     * @param string               $userId      The calling user ID
     *
     * @return bool True when the user is authorised.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $meetingUuid kept for symmetry with requireParticipantOrAdmin.
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
     */
    private function requireChairOrAdmin(string $meetingUuid, array $meeting, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isAdmin(userId: $userId) === true) {
            return true;
        }

        $chairUserId = $meeting['chair'] ?? null;
        if ($chairUserId !== null && (string) $chairUserId === $userId) {
            return true;
        }

        return false;

    }//end requireChairOrAdmin()

    /**
     * Check whether the calling user is a participant of the meeting or a system admin.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - Participants are identified by the 'participants' field (array of user IDs)
     *   or by the chair field.
     * - Admin is resolved via IGroupManager::isAdmin() (NC system admin group).
     * - This helper MUST actually run — it does not return true unconditionally.
     *
     * @param string               $meetingUuid The meeting UUID (for context)
     * @param array<string, mixed> $meeting     The meeting data array
     * @param string               $userId      The calling user ID
     *
     * @return bool True when the user is authorised.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $meetingUuid kept for symmetry and future logging.
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
     */
    private function requireParticipantOrAdmin(string $meetingUuid, array $meeting, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isAdmin(userId: $userId) === true) {
            return true;
        }

        // Use ParticipantResolver to check via the canonical schema path:
        // meeting → governanceBody → participants (fixes C3/H1: the meeting
        // data `participants` array is unreliable and `@self.relations.meeting`
        // doesn't exist on the participant schema).
        return $this->participantResolver->isParticipant(
            meetingId: $meetingUuid,
            nextcloudUid: $userId,
        );

    }//end requireParticipantOrAdmin()

    /**
     * Check whether the user is a Nextcloud system administrator.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return bool True when the user is a system admin.
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
     */
    private function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);

    }//end isAdmin()

    /**
     * Return the set of meeting UUIDs the caller is a participant of.
     *
     * Used to scope `scope=all` MCP tool results to meetings the calling
     * user legitimately participates in, preventing cross-governance-body
     * data exposure (OWASP A01:2021 — Broken Access Control / ADR-005).
     *
     * Admins receive null (no restriction — all meetings visible).
     *
     * @param string $userId Nextcloud UID of the caller
     *
     * @return array<string>|null Set of meeting UUIDs, or null for unrestricted admin
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
     */
    private function getCallerMeetingUuids(string $userId): ?array
    {
        if ($this->isAdmin(userId: $userId) === true) {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Find all participant records for this Nextcloud user.
            $participants = $objectService->findAll(
                [
                    'filters' => [
                        'register'        => 'decidesk',
                        'schema'          => 'participant',
                        'nextcloudUserId' => $userId,
                    ],
                ]
            );

            // Participants have no direct meeting relation; canonical path is
            // participant → governance-body → meeting (ParticipantResolver docblock).
            $meetingUuids = [];
            foreach ($participants as $raw) {
                $p      = $this->toArray(item: $raw);
                $bodyId = null;

                foreach (($p['relations'] ?? []) as $rel) {
                    if (is_array($rel) === true && ($rel['schema'] ?? '') === 'governance-body') {
                        $bodyId = ($rel['id'] ?? null);
                        break;
                    }
                }

                if ($bodyId === null) {
                    continue;
                }

                // Query meetings linked to this governance body.
                $meetingEntities = $objectService->findAll(
                    [
                        'filters' => [
                            'register'                  => 'decidesk',
                            'schema'                    => 'meeting',
                            'relations.governance-body' => $bodyId,
                        ],
                    ]
                );

                foreach ($meetingEntities as $meetingRaw) {
                    $m   = $this->toArray(item: $meetingRaw);
                    $mId = ($m['id'] ?? ($m['uuid'] ?? null));
                    if ($mId !== null) {
                        $meetingUuids[] = (string) $mId;
                    }
                }
            }//end foreach

            return array_unique(array_filter($meetingUuids));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk MCP: could not resolve caller meeting UUIDs, defaulting to empty',
                ['userId' => $userId, 'error' => $e->getMessage()]
            );
            // Fail closed: if we can't determine memberships, return no meetings.
            return [];
        }//end try

    }//end getCallerMeetingUuids()

    /**
     * Build a deep link URL for a decidesk resource.
     *
     * @param string $type One of: meeting, agendaItem, decision, actionItem
     * @param string $uuid The object UUID
     *
     * @return string The deep link path, e.g. /apps/decidesk/meetings/<uuid>.
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
     */
    private function buildDeepLink(string $type, string $uuid): string
    {
        $paths = [
            'meeting'    => '/apps/decidesk/meetings',
            'agendaItem' => '/apps/decidesk/agenda-items',
            'decision'   => '/apps/decidesk/decisions',
            'actionItem' => '/apps/decidesk/action-items',
        ];

        $base = $paths[$type] ?? "/apps/decidesk/{$type}s";
        return "{$base}/{$uuid}";

    }//end buildDeepLink()

    /**
     * Truncate a sources array to at most SOURCES_CAP elements.
     *
     * Returns a structure with:
     * - truncated:   the (possibly capped) sources array
     * - totalCount:  the original count before truncation
     * - didTruncate: bool — true when the array was capped
     *
     * @param array<int, array<string, mixed>> $sources The full sources array
     *
     * @return array{truncated: array<int, array<string, mixed>>, totalCount: int, didTruncate: bool}
     *
     * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
     */
    private function truncateSources(array $sources): array
    {
        $totalCount  = count($sources);
        $didTruncate = ($totalCount > self::SOURCES_CAP);
        $truncated   = $sources;
        if ($didTruncate === true) {
            $truncated = array_slice(array: $sources, offset: 0, length: self::SOURCES_CAP);
        }

        return [
            'truncated'   => $truncated,
            'totalCount'  => $totalCount,
            'didTruncate' => $didTruncate,
        ];

    }//end truncateSources()

    /**
     * Normalise an OpenRegister object to a plain PHP array.
     *
     * @param mixed $item Raw item from ObjectService
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array(value: $item) === true) {
            return $item;
        }

        if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {
            return $item->getObject();
        }

        if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        return (array) $item;

    }//end toArray()

    /**
     * Extract the UUID from a normalised object array.
     *
     * Checks multiple common field names to handle different OR object shapes.
     *
     * @param array<string, mixed> $item The normalised object array
     *
     * @return string The UUID, or empty string when not found.
     */
    private function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()
}//end class
