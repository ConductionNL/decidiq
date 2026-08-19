<?php

/**
 * Decidesk Conflict-of-Interest Service
 *
 * Manages `conflict-of-interest` declarations: detection, declaration, action
 * recording, and lookup. Material declarations append a `conflict-declaration`
 * row to the board audit log.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for managing conflict-of-interest declarations and their effect on
 * read/vote access to specific agenda items.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */
class ConflictOfInterestService {

	/**
	 * Allowed declaration-type enum values.
	 *
	 * @var string[]
	 */
	public const DECLARATION_TYPES = [
		'financial-interest',
		'personal-relationship',
		'competing-business',
		'prior-involvement',
		'none',
	];

	/**
	 * Allowed action-taken enum values.
	 *
	 * @var string[]
	 */
	public const ACTIONS = [
		'recused-from-discussion',
		'recused-from-vote',
		'disclosed-and-participated',
		'no-action-needed',
	];

	/**
	 * Allowed severity values.
	 *
	 * @var string[]
	 */
	public const SEVERITIES = ['material', 'non-material'];

	/**
	 * Constructor for ConflictOfInterestService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param AuditLogService $auditLogService Audit log dependency for material declarations
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Record a new declaration. Material declarations are mirrored to the
	 * audit log and the resulting object is returned alongside success status.
	 *
	 * @param string $membershipId UUID of the declaring member's Membership (was Participant — REQ-SDM-023)
	 * @param string $agendaItemId UUID of the agenda item
	 * @param string $type One of self::DECLARATION_TYPES
	 * @param string $description Free-text rationale
	 * @param string $severity 'material' or 'non-material' (defaults to material)
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/model-debt-cleanup-code/proposal.md#in-scope
	 *
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	public function declare(
		string $membershipId,
		string $agendaItemId,
		string $type,
		string $description,
		string $severity = 'material',
	): array {
		if (in_array($type, self::DECLARATION_TYPES, true) === false) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown declaration type: ' . $type,
			];
		}

		if (in_array($severity, self::SEVERITIES, true) === false) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown severity: ' . $severity,
			];
		}

		try {
			// The ConflictOfInterest schema declares 'boardMember'/'agendaItem'
			// (decidesk_register.json); this previously wrote 'boardMemberKoppeling'/
			// 'agendaItemKoppeling' instead, an undeclared pair no filter or reader
			// ever matched — every declaration silently stored its member/agenda-item
			// link nowhere the schema (or the model-debt-cleanup-code repair step)
			// could find it. Fixed in the same edit that renames the parameter,
			// since both touch this exact line.
			$row = [
				'boardMember' => $membershipId,
				'agendaItem' => $agendaItemId,
				'declarationType' => $type,
				'description' => $description,
				'severity' => $severity,
				'actionTaken' => 'no-action-needed',
				'declarationTimestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			];

			$saved = $this->objectService->saveObject(
				object: $row,
				register: 'decidesk',
				schema: 'conflict-of-interest'
			);

			$serialized = $row;
			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				$serialized = $saved->jsonSerialize();
			}

			if ($severity === 'material') {
				$this->auditLogService->append(
					actor: $membershipId,
					action: 'conflict-declaration',
					objectUids: [(string)($serialized['id'] ?? $serialized['uuid'] ?? ''), $agendaItemId],
					payload: ['type' => $type, 'severity' => $severity]
				);
			}

			$this->logger->info(
				'Decidesk: conflict-of-interest declared',
				[
					'membershipId' => $membershipId,
					'agendaItemId' => $agendaItemId,
					'type' => $type,
					'severity' => $severity,
				]
			);

			return [
				'success' => true,
				'declaration' => $serialized,
				'message' => 'Declaration recorded.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: failed to record conflict-of-interest declaration',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Failed to record declaration.',
			];
		}//end try

	}//end declare()

	/**
	 * Update the action-taken on an existing declaration.
	 *
	 * @param string $declarationId UUID of the conflict-of-interest record
	 * @param string $actionTaken One of self::ACTIONS
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
	 *
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	public function recordAction(string $declarationId, string $actionTaken): array {
		if (in_array($actionTaken, self::ACTIONS, true) === false) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown action-taken: ' . $actionTaken,
			];
		}

		try {
			$entity = $this->objectService->find(
				id: $declarationId,
				register: 'decidesk',
				schema: 'conflict-of-interest'
			);

			if ($entity === null) {
				return [
					'success' => false,
					'declaration' => null,
					'message' => 'Declaration not found.',
				];
			}

			$current = (array)$entity->jsonSerialize();
			if (method_exists($entity, 'getObject') === true) {
				$current = $entity->getObject();
			}

			$updated = array_merge($current, ['actionTaken' => $actionTaken]);

			$saved = $this->objectService->saveObject(
				object: $updated,
				register: 'decidesk',
				schema: 'conflict-of-interest',
				uuid: $declarationId
			);

			$payload = $updated;
			if (is_object($saved) === true) {
				$payload = $saved->jsonSerialize();
			}

			return [
				'success' => true,
				'declaration' => $payload,
				'message' => 'Action recorded.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: failed to update conflict action',
				['declarationId' => $declarationId, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Failed to record action.',
			];
		}//end try

	}//end recordAction()

	/**
	 * Return the most-restrictive active conflict for a given member + agenda
	 * item pair, or null when none is on file. Restrictiveness ordering is
	 * "recused-from-vote" > "recused-from-discussion" > "disclosed-and-participated"
	 * > "no-action-needed".
	 *
	 * @param string $membershipId UUID of the member's Membership (was Participant)
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/model-debt-cleanup-code/proposal.md#in-scope
	 *
	 * @return array<string, mixed>|null
	 */
	public function getActiveConflicts(string $membershipId, string $agendaItemId): ?array {
		$matches = $this->findDeclarations(membershipId: $membershipId, agendaItemId: $agendaItemId);
		if ($matches === []) {
			return null;
		}

		$weight = [
			'recused-from-vote' => 4,
			'recused-from-discussion' => 3,
			'disclosed-and-participated' => 2,
			'no-action-needed' => 1,
		];

		usort(
			$matches,
			static function (array $a, array $b) use ($weight): int {
				return (($weight[$b['actionTaken'] ?? 'no-action-needed'] ?? 0) - ($weight[$a['actionTaken'] ?? 'no-action-needed'] ?? 0));
			}
		);

		return $matches[0];
	}//end getActiveConflicts()

	/**
	 * Internal: list all declarations matching the given member + agenda item.
	 *
	 * @param string $membershipId UUID of the member's Membership (was Participant)
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findDeclarations(string $membershipId, string $agendaItemId): array {
		try {
			// ObjectService::prepareFindAllConfig() only reads the register/schema
			// context from filters.register / filters.schema — a TOP-LEVEL
			// 'register'/'schema' key (as this call previously used) is silently
			// ignored, so findAll() ran with no register/schema context and
			// returned nothing (same landmine documented on
			// ProxyVoteService::forMeeting(), decidesk#443).
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidesk',
						'schema' => 'conflict-of-interest',
						'boardMember' => $membershipId,
						'agendaItem' => $agendaItemId,
					],
					'limit' => 100,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: failed to query conflict declarations',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === false) {
				continue;
			}

			$memberMatches = (($row['boardMember'] ?? null) === $membershipId);
			$itemMatches = (($row['agendaItem'] ?? null) === $agendaItemId);
			if ($memberMatches === true && $itemMatches === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end findDeclarations()
}//end class
