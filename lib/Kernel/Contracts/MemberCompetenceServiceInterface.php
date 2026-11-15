<?php

/**
 * Contract of MemberCompetenceService
 *
 * The public surface of MemberCompetenceService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\CompetenceRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of MemberCompetenceService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MemberCompetenceServiceInterface {

	/**
	 * Confirm a member competence as the signed-in signatory.
	 *
	 * @param string $id The member competence
	 *
	 * @return array<string, mixed> The stored member competence, with its id
	 *
	 * @throws MissingObjectException When it or its membership does not exist
	 * @throws AccessDeniedException  When the caller is the holder or not a signatory of the body
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function confirm(string $id): array;

	/**
	 * Record a member competence, or change one. Its membership and
	 * competence never move; changing the level clears a confirmation, and a
	 * payload never sets one.
	 *
	 * @param array<string, mixed> $data The fields: membership and competence (on create), level, note
	 * @param string|null          $id   The member competence, null to record one
	 *
	 * @return array<string, mixed> The stored member competence, with its id
	 *
	 * @throws MissingObjectException     When it, its membership or its competence does not exist
	 * @throws AccessDeniedException      When the caller is neither the member nor a signatory of the body
	 * @throws CompetenceRefusedException When the level is unknown or the competence belongs to another body
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function record(array $data, ?string $id): array;

	/**
	 * Add a competence to a body, or change one. The body of an existing
	 * competence never moves.
	 *
	 * @param array<string, mixed> $data The fields: governanceBody (on create), name, description, requiredHolders, order, active
	 * @param string|null          $id   The competence, null to add one
	 *
	 * @return array<string, mixed> The stored competence, with its id
	 *
	 * @throws MissingObjectException     When the competence or the body does not exist
	 * @throws AccessDeniedException      When the caller is not a signatory of the body
	 * @throws CompetenceRefusedException When the name is empty or fewer than one holder is required
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 */
	public function saveCompetence(array $data, ?string $id): array;
}//end interface
