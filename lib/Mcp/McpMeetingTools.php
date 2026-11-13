<?php

/**
 * Decidiq MCP Meeting Tools
 *
 * Implements the three meeting MCP tools — decidesk.listRecentMeetings,
 * decidesk.getMeetingDetails and decidesk.startMeeting — behind the
 * DecidiqToolProvider dispatcher.
 *
 * @category Mcp
 * @package  OCA\Decidiq\Mcp
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

namespace OCA\Decidiq\Mcp;

use OCA\Decidiq\Kernel\Contracts\MeetingServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The meeting half of the Decidiq MCP tool catalogue.
 *
 * Extracted from DecidiqToolProvider (which now only dispatches) so that the
 * three handlers and their supporting logic form a class of their own rather
 * than 400 lines inside a 1200-line provider.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpMeetingTools {

	/**
	 * The objects a meeting's details include, in the order they are read:
	 * the source type each is cited as and the fields its label is read from
	 * (the last one a fixed fallback).
	 *
	 * @var array<string, array{sourceType: string, labels: list<string>}>
	 */
	private const RELATED_OBJECTS = [
		'agenda-item' => ['sourceType' => 'decidesk.agendaItem', 'labels' => ['title', 'subject', 'Agenda item']],
		'decision' => ['sourceType' => 'decidesk.decision', 'labels' => ['title', 'text', 'Decision']],
		'action-item' => ['sourceType' => 'decidesk.actionItem', 'labels' => ['title', 'name', 'Action item']],
	];

	/**
	 * Constructor for the McpMeetingTools.
	 *
	 * @param MeetingServiceInterface $meetingService The meeting service driving lifecycle transitions
	 * @param LoggerInterface $logger The PSR-3 logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param McpMeetingGate $gate Loads meetings and enforces per-object authorisation
	 * @param McpSourceFormatter $formatter Builds the citation payloads and error envelopes
	 * @param McpArgumentValidator $validator Validates the LLM-supplied tool arguments
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MeetingServiceInterface $meetingService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly McpMeetingGate $gate,
		private readonly McpSourceFormatter $formatter,
		private readonly McpArgumentValidator $validator,
	) {
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

		$signedOut = $this->gate->signedOutError();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			return $this->collectRecentMeetings(limit: $limit, statusFilter: (string)$statusFilter);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq MCP: listRecentMeetings failed',
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
				'Decidiq MCP: getMeetingDetails failed',
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
				$this->formatter->meetingSource(meetingUuid: $meetingUuid, meeting: $meeting),
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
			'register' => 'decidiq',
			'schema' => 'meeting',
			'_limit' => $limit,
			'_order' => ['scheduledDate' => 'DESC'],
		];

		if ($statusFilter !== 'any') {
			$filters['lifecycle'] = $statusFilter;
		}

		$rawMeetings = $this->objectService->findAll(['filters' => $filters]);

		$allowedUuids = $this->gate->callerMeetingUuids(userId: $this->gate->currentUserId());

		$meetings = [];
		$sources = [];
		foreach ($rawMeetings as $raw) {
			$meeting = $this->formatter->toArray(item: $raw);
			$meetingUuid = $this->formatter->extractUuid(item: $meeting);

			if ($allowedUuids !== null && in_array($meetingUuid, $allowedUuids, true) === false) {
				continue;
			}

			$meetings[] = $meeting;
			$sources[] = $this->formatter->meetingSource(meetingUuid: $meetingUuid, meeting: $meeting);
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
		$related = [];
		foreach (array_keys(self::RELATED_OBJECTS) as $schema) {
			$related[$schema] = $this->relatedObjects(meetingUuid: $meetingUuid, schema: $schema);
		}

		$sources = array_merge(
			[
				$this->formatter->meetingSource(meetingUuid: $meetingUuid, meeting: $meeting),
			],
			$related['agenda-item']['sources'],
			$related['decision']['sources'],
			$related['action-item']['sources']
		);

		return $this->formatter->withSources(
			payload: [
				'success' => true,
				'meeting' => $meeting,
				'agendaItems' => $related['agenda-item']['items'],
				'decisions' => $related['decision']['items'],
				'actionItems' => $related['action-item']['items'],
			],
			sources: $sources
		);

	}//end collectMeetingDetails()

	/**
	 * The objects of one schema linked to the meeting, with one source each.
	 *
	 * @param string $meetingUuid The meeting
	 * @param string $schema      agenda-item, decision or action-item (see RELATED_OBJECTS)
	 *
	 * @return array{items: list<array<string, mixed>>, sources: list<array<string, mixed>>} The objects and their sources.
	 */
	private function relatedObjects(string $meetingUuid, string $schema): array {
		$kind = self::RELATED_OBJECTS[$schema];
		$raws = $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
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
				type: $kind['sourceType'],
				uuid: $this->formatter->extractUuid(item: $item),
				label: $this->pickLabel(item: $item, labels: $kind['labels']),
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
