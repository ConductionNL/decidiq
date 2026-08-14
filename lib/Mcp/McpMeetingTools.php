<?php

/**
 * Decidesk MCP Meeting Tools
 *
 * Implements the three meeting MCP tools — decidesk.listRecentMeetings,
 * decidesk.getMeetingDetails and decidesk.startMeeting — behind the
 * DecideskToolProvider dispatcher.
 *
 * @category Mcp
 * @package  OCA\Decidesk\Mcp
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Mcp;

use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The meeting half of the decidesk MCP tool catalogue.
 *
 * Extracted from DecideskToolProvider (which now only dispatches) so that the
 * three handlers and their supporting logic form a class of their own rather
 * than 400 lines inside a 1200-line provider.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpMeetingTools {

	/**
	 * Shapes tool results and source descriptors.
	 *
	 * @var McpSourceFormatter
	 */
	private readonly McpSourceFormatter $formatter;

	/**
	 * Validates the LLM-supplied tool arguments.
	 *
	 * @var McpArgumentValidator
	 */
	private readonly McpArgumentValidator $validator;

	/**
	 * Loads meetings and enforces per-object authorisation.
	 *
	 * @var McpMeetingGate
	 */
	private readonly McpMeetingGate $gate;

	/**
	 * Constructor for the McpMeetingTools.
	 *
	 * @param MeetingService $meetingService The meeting service driving lifecycle transitions
	 * @param ContainerInterface $container DI container used to reach OpenRegister
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager The group manager (for admin checks)
	 * @param LoggerInterface $logger The PSR-3 logger
	 * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MeetingService $meetingService,
		IUserSession $userSession,
		IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		ParticipantResolver $participantResolver,
		private readonly ObjectService $objectService,
	) {
		$this->formatter = new McpSourceFormatter();
		$this->validator = new McpArgumentValidator(formatter: $this->formatter);
		$this->gate = new McpMeetingGate(
			container: $container,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $logger,
			participantResolver: $participantResolver,
			formatter: $this->formatter,
			validator: $this->validator,
		);

	}//end __construct()

	/**
	 * Handle decidesk.listRecentMeetings.
	 *
	 * Returns recent meetings ordered by date descending.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function listRecentMeetings(array $args): array {
		$limit = 10;
		if (isset($args['limit']) === true) {
			$limit = (int)$args['limit'];
		}

		$statusFilter = $args['statusFilter'] ?? 'any';

		$invalid = $this->validator->validateLimitAndStatus(limit: $limit, statusFilter: $statusFilter);
		if ($invalid !== null) {
			return $invalid;
		}

		try {
			return $this->collectRecentMeetings(limit: $limit, statusFilter: (string)$statusFilter);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidesk MCP: listRecentMeetings failed',
				['exception' => $e->getMessage()]
			);
			return $this->formatter->error(
				code: 'internal_error',
				message: 'Failed to retrieve meetings. See server log for details.'
			);
		}//end try

	}//end listRecentMeetings()

	/**
	 * Handle decidesk.getMeetingDetails.
	 *
	 * Fetches a meeting with agenda items, decisions, and action items inlined.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function getMeetingDetails(array $args): array {
		$resolved = $this->gate->authorise(
			meetingUuid: ($args['meetingUuid'] ?? null),
			requirement: 'participant'
		);
		if (isset($resolved['error']) === true) {
			return $resolved['error'];
		}

		try {
			return $this->collectMeetingDetails(
				meetingUuid: $resolved['uuid'],
				meeting: $resolved['meeting']
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidesk MCP: getMeetingDetails failed',
				['meetingUuid' => $resolved['uuid'], 'exception' => $e->getMessage()]
			);
			return $this->formatter->error(
				code: 'internal_error',
				message: 'Failed to retrieve meeting details. See server log for details.'
			);
		}//end try

	}//end getMeetingDetails()

	/**
	 * Handle decidesk.startMeeting.
	 *
	 * Transitions a scheduled meeting to opened (in-progress).
	 * Only the chair or an admin may do this.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function startMeeting(array $args): array {
		$resolved = $this->gate->authorise(
			meetingUuid: ($args['meetingUuid'] ?? null),
			requirement: 'chair'
		);
		if (isset($resolved['error']) === true) {
			return $resolved['error'];
		}

		$meeting = $resolved['meeting'];
		$meetingUuid = $resolved['uuid'];

		// State guard: only scheduled meetings can be opened (REQ-DMCP-005).
		$stateError = $this->openStateError(meeting: $meeting);
		if ($stateError !== null) {
			return $stateError;
		}

		$result = $this->meetingService->transition(
			meetingId: $meetingUuid,
			action: 'open',
			currentUserId: $resolved['userId'],
		);

		if ($result['success'] === false) {
			return $this->formatter->error(code: 'internal_error', message: $result['message']);
		}

		return [
			'success' => true,
			'started' => true,
			'meetingUuid' => $meetingUuid,
			'startedAt' => $this->formatter->nowIso(),
			'sources' => [
				$this->formatter->source(
					type: 'decidesk.meeting',
					uuid: $meetingUuid,
					label: (string)($meeting['title'] ?? 'Meeting'),
				),
			],
		];

	}//end startMeeting()

	/**
	 * Query and shape the caller's recent meetings.
	 *
	 * Admins see all meetings; non-admins see only meetings belonging to their
	 * own governance bodies (OWASP A01:2021 / ADR-005).
	 *
	 * @param int $limit Maximum number of meetings to return
	 * @param string $statusFilter The lifecycle filter, or "any"
	 *
	 * @return array<string, mixed> The success payload.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function collectRecentMeetings(int $limit, string $statusFilter): array {
		$filters = [
			'register' => 'decidesk',
			'schema' => 'meeting',
			'_limit' => $limit,
			'_order' => ['scheduledDate' => 'DESC'],
		];

		if ($statusFilter !== 'any') {
			$filters['lifecycle'] = $statusFilter;
		}

		$rawMeetings = $this->objectService->findAll(['filters' => $filters]);

		$currentUserId = $this->gate->currentUserId();
		$allowedUuids = null;
		if ($currentUserId !== '') {
			$allowedUuids = $this->gate->callerMeetingUuids(userId: $currentUserId);
		}

		$meetings = [];
		$sources = [];
		foreach ($rawMeetings as $raw) {
			$meeting = $this->formatter->toArray(item: $raw);
			$meetingUuid = $this->formatter->extractUuid(item: $meeting);

			if ($allowedUuids !== null && in_array($meetingUuid, $allowedUuids, true) === false) {
				continue;
			}

			$meetings[] = $meeting;
			$sources[] = $this->formatter->source(
				type: 'decidesk.meeting',
				uuid: $meetingUuid,
				label: (string)($meeting['title'] ?? 'Meeting'),
			);
		}//end foreach

		return $this->formatter->withSources(
			payload: [
				'success' => true,
				'meetings' => $meetings,
			],
			sources: $sources
		);

	}//end collectRecentMeetings()

	/**
	 * Inline a meeting's agenda items, decisions and action items.
	 *
	 * @param string $meetingUuid The authorised meeting UUID
	 * @param array<string, mixed> $meeting The authorised meeting
	 *
	 * @return array<string, mixed> The success payload.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function collectMeetingDetails(string $meetingUuid, array $meeting): array {
		$agenda = $this->relatedObjects(
			objectService: $objectService,
			meetingUuid: $meetingUuid,
			schema: 'agenda-item',
			sourceType: 'decidesk.agendaItem',
			labels: ['title', 'subject', 'Agenda item'],
		);

		$decisions = $this->relatedObjects(
			objectService: $objectService,
			meetingUuid: $meetingUuid,
			schema: 'decision',
			sourceType: 'decidesk.decision',
			labels: ['title', 'text', 'Decision'],
		);

		$actions = $this->relatedObjects(
			objectService: $objectService,
			meetingUuid: $meetingUuid,
			schema: 'action-item',
			sourceType: 'decidesk.actionItem',
			labels: ['title', 'name', 'Action item'],
		);

		$sources = array_merge(
			[
				$this->formatter->source(
					type: 'decidesk.meeting',
					uuid: $meetingUuid,
					label: (string)($meeting['title'] ?? 'Meeting'),
				),
			],
			$agenda['sources'],
			$decisions['sources'],
			$actions['sources']
		);

		return $this->formatter->withSources(
			payload: [
				'success' => true,
				'meeting' => $meeting,
				'agendaItems' => $agenda['items'],
				'decisions' => $decisions['items'],
				'actionItems' => $actions['items'],
			],
			sources: $sources
		);

	}//end collectMeetingDetails()

	/**
	 * Fetch every object of one schema related to a meeting, with its sources.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $meetingUuid The meeting UUID to filter on
	 * @param string $schema The OpenRegister schema slug
	 * @param string $sourceType The MCP source type for these objects
	 * @param array<string> $labels Candidate label keys, last element is the fallback label
	 *
	 * @return array{items: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function relatedObjects(
		object $objectService,
		string $meetingUuid,
		string $schema,
		string $sourceType,
		array $labels,
	): array {
		$raws = $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidesk',
					'schema' => $schema,
					'_relations.meeting' => $meetingUuid,
				],
			]
		);

		$items = [];
		$sources = [];
		foreach ($raws as $raw) {
			$item = $this->formatter->toArray(item: $raw);
			$items[] = $item;
			$sources[] = $this->formatter->source(
				type: $sourceType,
				uuid: $this->formatter->extractUuid(item: $item),
				label: $this->pickLabel(item: $item, labels: $labels),
			);
		}

		return [
			'items' => $items,
			'sources' => $sources,
		];

	}//end relatedObjects()

	/**
	 * Pick the first present label key, falling back to the final entry.
	 *
	 * @param array<string, mixed> $item The normalised object
	 * @param array<string> $labels Candidate keys, last element is the fallback label
	 *
	 * @return string The chosen label.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function pickLabel(array $item, array $labels): string {
		$fallback = array_pop($labels);
		foreach ($labels as $key) {
			if (isset($item[$key]) === true) {
				return (string)$item[$key];
			}
		}

		return (string)$fallback;
	}//end pickLabel()

	/**
	 * The invalid_state envelope when a meeting cannot be opened.
	 *
	 * @param array<string, mixed> $meeting The authorised meeting
	 *
	 * @return array<string, mixed>|null The error envelope, or null when the meeting may be opened.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function openStateError(array $meeting): ?array {
		$lifecycle = (string)($meeting['lifecycle'] ?? 'draft');
		if ($lifecycle === 'scheduled') {
			return null;
		}

		$stateLabel = $lifecycle;
		if ($lifecycle === 'opened') {
			$stateLabel = 'in progress';
		}

		return $this->formatter->error(
			code: 'invalid_state',
			message: "Meeting is already {$stateLabel}."
		);

	}//end openStateError()
}//end class
