<?php

/**
 * Decidiq Paper Summary Access
 *
 * Decides whether the signed-in user may ask for an AI summary of a paper:
 * only the secretariat and administrators may, and not for a paper under an
 * active confidentiality restriction (on the agenda item or on the paper)
 * when the user is outside the restriction's circle, the members of the body
 * that imposed it. Register rows are read in system context and re-checked
 * here, so a filter OpenRegister ignores can only widen the read.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who may ask for a paper summary.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
 */
class PaperSummaryAccess {

	/**
	 * The groups that may ask for a summary.
	 */
	private const CLERK_GROUPS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * Restriction states that keep a paper inside its circle.
	 */
	private const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session
	 * @param IGroupManager $groupManager Group membership
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Refuse the request unless the user may ask for a summary of this paper.
	 *
	 * @param string $agendaItemId The agenda item the paper is attached to
	 * @param int $fileId The paper's Nextcloud file id
	 *
	 * @return string The user id of the clerk asking.
	 *
	 * @throws PaperSummaryRefusedException 401 without a user, 403 outside the secretariat or the circle
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	public function assertMayRequest(string $agendaItemId, int $fileId): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new PaperSummaryRefusedException(message: 'Log in to ask for a summary.', status: 401);
		}

		if ($this->isClerk(uid: $uid) === false) {
			throw new PaperSummaryRefusedException(message: 'Only the secretariat can ask for a summary of a paper.');
		}

		$documentIds = array_column(
			array_filter(
				$this->rows(schema: 'digital-document', filters: ['fileId' => $fileId]),
				static fn (array $doc): bool => (int)($doc['fileId'] ?? 0) === $fileId
			),
			'id'
		);
		$bodies = $this->bodiesOf(uid: $uid);
		foreach ($this->rows(schema: 'confidentiality-restriction', filters: []) as $restriction) {
			if ($this->keepsOut(restriction: $restriction, agendaItemId: $agendaItemId, documentIds: $documentIds, bodies: $bodies) === false) {
				continue;
			}

			throw new PaperSummaryRefusedException(
				message: sprintf(
					'This paper is confidential (%s) and is summarised only within %s.',
					$this->nameOf(schema: 'confidentiality-ground', id: (string)($restriction['ground'] ?? '')),
					$this->nameOf(schema: 'governance-body', id: (string)($restriction['imposedByBody'] ?? ''))
				)
			);
		}

		return $uid;
	}//end assertMayRequest()

	/**
	 * Whether an active restriction on the item or on the paper keeps a user
	 * of these bodies out.
	 *
	 * @param array<string, mixed> $restriction The restriction
	 * @param string $agendaItemId The agenda item
	 * @param array<int, mixed> $documentIds The ids of the paper's document records
	 * @param array<int, string> $bodies The bodies the user is a member of
	 *
	 * @return bool True when the restriction refuses the request.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	private function keepsOut(array $restriction, string $agendaItemId, array $documentIds, array $bodies): bool {
		$scope = ($restriction['scope'] ?? '');
		$targetsPaper = ($scope === 'item' && ($restriction['targetAgendaItem'] ?? '') === $agendaItemId)
			|| ($scope === 'document' && in_array(($restriction['targetDocument'] ?? ''), $documentIds, true) === true);

		return $targetsPaper === true
			&& in_array(($restriction['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === true
			&& in_array(($restriction['imposedByBody'] ?? ''), $bodies, true) === false;
	}//end keepsOut()

	/**
	 * Whether the signed-in user may ask for summaries at all, for the page
	 * to show or leave out the actions.
	 *
	 * @return bool True for a signed-in clerk.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
	 */
	public function currentUserIsClerk(): bool {
		$uid = $this->userSession->getUser()?->getUID();

		return $uid !== null && $this->isClerk(uid: $uid) === true;
	}//end currentUserIsClerk()

	/**
	 * Whether the user is an administrator or in a clerk group.
	 *
	 * @param string $uid The user id
	 *
	 * @return bool True for a clerk.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	public function isClerk(string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::CLERK_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isClerk()

	/**
	 * The bodies the user's person holds a membership of.
	 *
	 * @param string $uid The user id
	 *
	 * @return array<int, string> Governance body ids.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	private function bodiesOf(string $uid): array {
		$personIds = array_column(
			array_filter(
				$this->rows(schema: 'person', filters: ['nextcloudUserId' => $uid]),
				static fn (array $person): bool => ($person['nextcloudUserId'] ?? null) === $uid
			),
			'id'
		);
		$bodies = [];
		foreach ($this->rows(schema: 'membership', filters: []) as $membership) {
			if (in_array(($membership['person'] ?? ''), $personIds, true) === true) {
				$bodies[] = (string)($membership['governanceBody'] ?? '');
			}
		}

		return $bodies;
	}//end bodiesOf()

	/**
	 * The name of one object, or its id when it has none or cannot be read.
	 *
	 * @param string $schema The schema slug
	 * @param string $id The object id
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	private function nameOf(string $schema, string $id): string {
		foreach ($this->rows(schema: $schema, filters: ['id' => $id]) as $row) {
			if (($row['id'] ?? null) === $id) {
				return (string)($row['name'] ?? $id);
			}
		}

		return $id;
	}//end nameOf()

	/**
	 * Rows of one schema as arrays, read in system context.
	 *
	 * @param string $schema The schema slug
	 * @param array<string, mixed> $filters Narrowing filters (re-checked by the caller)
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => 'decidiq', 'schema' => $schema], $filters)],
			_rbac: false,
			_multitenancy: false
		);
		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()
}//end class
