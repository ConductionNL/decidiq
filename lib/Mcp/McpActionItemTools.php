<?php

/**
 * Decidiq MCP Action Item Tools
 *
 * Implements the two action-item MCP tools — decidesk.listOpenActionItems and
 * decidesk.addActionItem — behind the DecidiqToolProvider dispatcher.
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

use OCA\Decidiq\Service\ActionItemWriter;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The action-item half of the decidesk MCP tool catalogue.
 *
 * Extracted from DecidiqToolProvider (which now only dispatches) so that the
 * two handlers and their supporting logic form a class of their own rather
 * than 270 lines inside a 1200-line provider.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpActionItemTools {

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
	 * Constructor for the McpActionItemTools.
	 *
	 * @param ContainerInterface $container DI container, used to resolve ActionItemWriter (OpenRegister now arrives as $objectService)
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager The group manager (for admin checks)
	 * @param LoggerInterface $logger The PSR-3 logger
	 * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		IUserSession $userSession,
		IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		ParticipantResolver $participantResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->formatter = new McpSourceFormatter();
		$this->validator = new McpArgumentValidator(formatter: $this->formatter);
		$this->gate = new McpMeetingGate(
			objectService: $objectService,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $logger,
			participantResolver: $participantResolver,
			formatter: $this->formatter,
			validator: $this->validator,
		);

	}//end __construct()

	/**
	 * Handle decidesk.listOpenActionItems.
	 *
	 * Returns incomplete action items scoped to mine or all visible.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function listOpenActionItems(array $args): array {
		$scope = $args['scope'] ?? 'mine';
		$limit = 20;
		if (isset($args['limit']) === true) {
			$limit = (int)$args['limit'];
		}

		$invalid = $this->validator->validateScopeAndLimit(scope: $scope, limit: $limit);
		if ($invalid !== null) {
			return $invalid;
		}

		try {
			return $this->collectOpenActionItems(scope: (string)$scope, limit: $limit);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq MCP: listOpenActionItems failed',
				['exception' => $e->getMessage()]
			);
			return $this->formatter->error(
				code: 'internal_error',
				message: 'Failed to retrieve action items. See server log for details.'
			);
		}//end try

	}//end listOpenActionItems()

	/**
	 * Handle decidesk.addActionItem.
	 *
	 * Creates an action item attached to a meeting.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function addActionItem(array $args): array {
		$invalid = $this->validator->validateActionItemArgs(args: $args);
		if ($invalid !== null) {
			return $invalid;
		}

		$resolved = $this->gate->authorise(
			meetingUuid: ($args['meetingUuid'] ?? null),
			requirement: 'participant'
		);
		if (isset($resolved['error']) === true) {
			return $resolved['error'];
		}

		try {
			return $this->createActionItem(
				args: $args,
				meeting: $resolved['meeting'],
				meetingUuid: $resolved['uuid']
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq MCP: addActionItem failed',
				['meetingUuid' => $resolved['uuid'], 'exception' => $e->getMessage()]
			);
			return $this->formatter->error(
				code: 'internal_error',
				message: 'Failed to create action item. See server log for details.'
			);
		}//end try

	}//end addActionItem()

	/**
	 * Query and shape the open action items for one scope.
	 *
	 * For scope=all, non-admins see only items linked to meetings they
	 * participate in — this prevents cross-governance-body data exposure
	 * (OWASP A01:2021 / ADR-005).
	 *
	 * @param string $scope Either "mine" or "all"
	 * @param int $limit Maximum number of items to return
	 *
	 * @return array<string, mixed> The success payload.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function collectOpenActionItems(string $scope, int $limit): array {
		$currentUserId = $this->gate->currentUserId();

		$filters = [
			'register' => 'decidesk',
			'schema' => 'action-item',
			'completed' => false,
			'_limit' => $limit,
		];

		if ($scope === 'mine' && $currentUserId !== '') {
			$filters['assignee'] = $currentUserId;
		}

		$rawItems = $this->objectService->findAll(['filters' => $filters]);

		$allowedUuids = null;
		if ($scope === 'all' && $currentUserId !== '') {
			$allowedUuids = $this->gate->callerMeetingUuids(userId: $currentUserId);
		}

		$items = [];
		$sources = [];
		foreach ($rawItems as $raw) {
			$item = $this->formatter->toArray(item: $raw);
			if ($this->isVisible(item: $item, allowedUuids: $allowedUuids) === false) {
				continue;
			}

			$items[] = $item;
			$sources[] = $this->formatter->source(
				type: 'decidesk.actionItem',
				uuid: $this->formatter->extractUuid(item: $item),
				label: (string)($item['title'] ?? $item['name'] ?? 'Action item'),
			);
		}//end foreach

		return $this->formatter->withSources(
			payload: [
				'success' => true,
				'items' => $items,
			],
			sources: $sources
		);

	}//end collectOpenActionItems()

	/**
	 * Decide whether one action item falls inside the caller's meeting scope.
	 *
	 * @param array<string, mixed> $item The normalised action item
	 * @param array<string>|null $allowedUuids The caller's meeting UUIDs, or null for unrestricted
	 *
	 * @return bool True when the item may be shown.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function isVisible(array $item, ?array $allowedUuids): bool {
		if ($allowedUuids === null) {
			return true;
		}

		$itemMeetingId = $this->meetingIdOf(item: $item);
		if ($itemMeetingId === null) {
			return false;
		}

		return in_array($itemMeetingId, $allowedUuids, true);
	}//end isVisible()

	/**
	 * Pick the meeting UUID an action item is linked to.
	 *
	 * Handles both the flattened `@self.relations.meeting` shape and the
	 * relations-array shape returned by different OpenRegister versions.
	 *
	 * @param array<string, mixed> $item The normalised action item
	 *
	 * @return string|null The meeting UUID, or null when absent.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function meetingIdOf(array $item): ?string {
		$itemMeetingId = $item['@self']['relations']['meeting'] ?? $item['meeting'] ?? null;
		if ($itemMeetingId !== null) {
			return (string)$itemMeetingId;
		}

		foreach (($item['relations'] ?? []) as $rel) {
			if (is_array($rel) === true && ($rel['schema'] ?? '') === 'meeting') {
				$relatedId = $rel['id'] ?? null;
				if ($relatedId === null) {
					return null;
				}

				return (string)$relatedId;
			}
		}

		return null;
	}//end meetingIdOf()

	/**
	 * Write the canonical CalDAV VTODO action item and shape the result.
	 *
	 * The action-item schema is a read-only VTODO projection (ADR-002 source of
	 * truth), so the write goes through ActionItemWriter rather than
	 * ObjectService::saveObject; the Deck integration leaf renders it as a
	 * board card (ADR-019).
	 *
	 * @param array<string, mixed> $args The validated tool arguments
	 * @param array<string, mixed> $meeting The authorised meeting
	 * @param string $meetingUuid The meeting UUID
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function createActionItem(array $args, array $meeting, string $meetingUuid): array {
		$title = (string)$args['title'];
		$actionItemData = [
			'title' => $title,
			'taskStatus' => 'open',
			'relations' => ['Meeting' => [$meetingUuid]],
		];

		$assigneeUserId = (string)($args['assigneeUserId'] ?? '');
		if ($assigneeUserId !== '') {
			$actionItemData['assignee'] = $assigneeUserId;
		}

		$dueDate = (string)($args['dueDate'] ?? '');
		if ($dueDate !== '') {
			$actionItemData['dueDate'] = $dueDate;
		}

		$writer = $this->container->get(ActionItemWriter::class);
		$saved = $writer->create(item: $actionItemData);
		if ($saved === null) {
			return $this->formatter->error(
				code: 'create_failed',
				message: 'Could not create the action item.'
			);
		}

		$itemUuid = (string)($saved['uid'] ?? $saved['id'] ?? '');

		return [
			'success' => true,
			'created' => true,
			'actionItem' => $saved,
			'sources' => [
				$this->formatter->source(type: 'decidesk.actionItem', uuid: $itemUuid, label: $title),
				$this->formatter->source(
					type: 'decidesk.meeting',
					uuid: $meetingUuid,
					label: (string)($meeting['title'] ?? 'Meeting'),
				),
			],
		];

	}//end createActionItem()
}//end class
