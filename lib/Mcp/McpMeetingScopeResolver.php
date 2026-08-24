<?php

/**
 * Decidiq MCP Meeting Scope Resolver
 *
 * Resolves the set of meetings an MCP caller may legitimately see, so that
 * `scope=all` tool results can be narrowed to the caller's own governance
 * bodies.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the meeting UUIDs an MCP caller participates in.
 *
 * Extracted from DecidiqToolProvider so the membership walk
 * (participant -> governance-body -> meeting) is a unit of its own rather
 * than a branch-heavy private method inside a 1200-line tool provider.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpMeetingScopeResolver {
	/**
	 * Constructor for the McpMeetingScopeResolver.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service (ADR-084)
	 * @param IGroupManager $groupManager Group manager backing the admin gate
	 * @param LoggerInterface $logger Logger for the fail-closed path
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the set of meeting UUIDs the caller is a participant of.
	 *
	 * Used to scope `scope=all` MCP tool results to meetings the calling
	 * user legitimately participates in, preventing cross-governance-body
	 * data exposure (OWASP A01:2021 - Broken Access Control / ADR-005).
	 *
	 * Admins receive null (no restriction - all meetings visible).
	 *
	 * @param string $userId Nextcloud UID of the caller
	 *
	 * @return array<string>|null Set of meeting UUIDs, or null for unrestricted admin
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function callerMeetingUuids(string $userId): ?array {
		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		try {
			// Find all participant records for this Nextcloud user.
			$participants = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'participant',
						'nextcloudUserId' => $userId,
					],
				]
			);

			// Participants have no direct meeting relation; canonical path is
			// participant -> governance-body -> meeting (ParticipantResolver docblock).
			$meetingUuids = [];
			foreach ($participants as $raw) {
				$bodyId = $this->governanceBodyId(participant: $this->toArray(item: $raw));
				if ($bodyId === null) {
					continue;
				}

				$meetingUuids = array_merge(
					$meetingUuids,
					$this->meetingUuidsForBody(objectService: $this->objectService, bodyId: $bodyId)
				);
			}

			return array_unique(array_filter($meetingUuids));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq MCP: could not resolve caller meeting UUIDs, defaulting to empty',
				['userId' => $userId, 'error' => $e->getMessage()]
			);
			// Fail closed: if we can't determine memberships, return no meetings.
			return [];
		}//end try

	}//end callerMeetingUuids()

	/**
	 * Pick the governance-body id out of a participant's relations.
	 *
	 * @param array<string, mixed> $participant The normalised participant object
	 *
	 * @return string|null The governance-body UUID, or null when absent.
	 */
	private function governanceBodyId(array $participant): ?string {
		foreach (($participant['relations'] ?? []) as $rel) {
			if (is_array($rel) === true && ($rel['schema'] ?? '') === 'governance-body') {
				$bodyId = ($rel['id'] ?? null);
				if ($bodyId === null) {
					return null;
				}

				return (string)$bodyId;
			}
		}

		return null;
	}//end governanceBodyId()

	/**
	 * List the meeting UUIDs linked to one governance body.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $bodyId The governance-body UUID
	 *
	 * @return array<string> The meeting UUIDs.
	 */
	private function meetingUuidsForBody(object $objectService, string $bodyId): array {
		$meetingEntities = $objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'meeting',
					'_relations.governance-body' => $bodyId,
				],
			]
		);

		$uuids = [];
		foreach ($meetingEntities as $meetingRaw) {
			$meeting = $this->toArray(item: $meetingRaw);
			$meetingId = ($meeting['id'] ?? ($meeting['uuid'] ?? null));
			if ($meetingId !== null) {
				$uuids[] = (string)$meetingId;
			}
		}

		return $uuids;
	}//end meetingUuidsForBody()

	/**
	 * Normalise an OpenRegister result item to a plain array.
	 *
	 * @param mixed $item The raw item returned by findAll()
	 *
	 * @return array<string, mixed> The normalised array.
	 */
	private function toArray(mixed $item): array {
		if (is_array(value: $item) === true) {
			return $item;
		}

		if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {
			return $item->getObject();
		}

		if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
			return $item->jsonSerialize();
		}

		return (array)$item;
	}//end toArray()
}//end class
