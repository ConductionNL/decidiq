<?php

/**
 * Decidiq Competence Confirmation Guard
 *
 * Who may change a body's competences, record a member's competence and
 * confirm one (bodies-board-composition-skills-and-diversity, design D2).
 * OpenRegister cannot express these rules in a schema block because they
 * depend on the signatory scope of the related membership's body, so the
 * competence writes go through MemberCompetenceService, which asks this
 * guard first (ADR-031 exception for guards).
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
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCP\IGroupManager;

/**
 * The competence write rules.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */
class CompetenceConfirmationGuard {
	/**
	 * Constructor.
	 *
	 * @param GovernanceScopeGuard $scopeGuard   The per-body scope groups
	 * @param IGroupManager        $groupManager Nextcloud groups (administrators)
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function __construct(
		private readonly GovernanceScopeGuard $scopeGuard,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse anyone but a signatory of the body or an administrator from
	 * changing the body's competences (REQ-BCS-001).
	 *
	 * @param string $userId The caller
	 * @param string $bodyId The body
	 *
	 * @return void
	 *
	 * @throws AccessDeniedException When the caller may not
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 */
	public function requireBodyManager(string $userId, string $bodyId): void {
		if ($this->isAdmin(userId: $userId) === true || $this->isSignatory(userId: $userId, bodyId: $bodyId) === true) {
			return;
		}

		throw new AccessDeniedException(message: 'Only a signatory of this body changes its competences.');
	}//end requireBodyManager()

	/**
	 * Refuse anyone but the member themselves, a signatory of the
	 * membership's body or an administrator from recording a competence on
	 * the membership.
	 *
	 * @param string $userId     The caller
	 * @param string $bodyId     The membership's body
	 * @param string $holderUser The Nextcloud account of the membership's person, '' when unknown
	 *
	 * @return void
	 *
	 * @throws AccessDeniedException When the caller may not
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function requireRecorder(string $userId, string $bodyId, string $holderUser): void {
		if ($holderUser !== '' && $holderUser === $userId) {
			return;
		}

		$this->requireBodyManager(userId: $userId, bodyId: $bodyId);
	}//end requireRecorder()

	/**
	 * Refuse anyone but a signatory of the membership's body from confirming
	 * a competence, and refuse the holder even when they are a signatory: a
	 * confirmation is someone else's word.
	 *
	 * @param string $userId     The caller
	 * @param string $bodyId     The membership's body
	 * @param string $holderUser The Nextcloud account of the membership's person, '' when unknown
	 *
	 * @return void
	 *
	 * @throws AccessDeniedException When the caller may not
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function requireConfirmer(string $userId, string $bodyId, string $holderUser): void {
		if ($holderUser !== '' && $holderUser === $userId) {
			throw new AccessDeniedException(message: 'A member cannot confirm their own competence.');
		}

		if ($this->isSignatory(userId: $userId, bodyId: $bodyId) === false) {
			throw new AccessDeniedException(message: 'Only a signatory of this body confirms a competence.');
		}
	}//end requireConfirmer()

	/**
	 * Whether the caller is in the body's signatory scope.
	 *
	 * @param string $userId The caller
	 * @param string $bodyId The body
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	private function isSignatory(string $userId, string $bodyId): bool {
		return $this->scopeGuard->isInBodyScope(userId: $userId, bodyId: $bodyId, scope: GovernanceScopeGuard::SCOPE_SIGNATORY);
	}//end isSignatory()

	/**
	 * Whether the caller is a Nextcloud administrator.
	 *
	 * @param string $userId The caller
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 */
	private function isAdmin(string $userId): bool {
		return $userId !== '' && $this->groupManager->isAdmin($userId) === true;
	}//end isAdmin()
}//end class
