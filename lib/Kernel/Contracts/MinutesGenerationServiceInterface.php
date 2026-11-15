<?php

/**
 * Contract of MinutesGenerationService
 *
 * The public surface of MinutesGenerationService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of MinutesGenerationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MinutesGenerationServiceInterface {

	/**
	 * Generate an initial minutes draft for the given minutes record.
	 *
	 * Fetches the Minutes object, resolves its linked Meeting, and retrieves the
	 * Meeting's AgendaItems (sorted by orderNumber), Motions, VotingRounds, and
	 * Decisions. Renders these into a structured Dutch text template.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @throws InvalidArgumentException When the Minutes object cannot be found
	 * @throws RuntimeException When OpenRegister is not available
	 *
	 * @return string The generated Dutch minutes text
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-1
	 */
	public function generateDraft(string $minutesId): string;

	/**
	 * Reject Minutes in review back to draft with a mandatory comment.
	 *
	 * The forward LIFECYCLE_TRANSITIONS map stays intact (signing/eIDAS flows
	 * depend on it); rejection is the single guarded backward step the spec's
	 * review loop requires: only `review` → `draft` is possible, the comment is
	 * mandatory, and the rejection is recorded in `reviewComments` with
	 * server-side author attribution so client-side forgery is impossible.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $comment The mandatory rejection comment
	 * @param string $userId Nextcloud UID of the rejecting user (from server session)
	 *
	 * @throws InvalidArgumentException When the Minutes object is not found (MissingObjectException), the comment is empty or lifecycle is not review
	 * @throws RuntimeException When OpenRegister is not available
	 *
	 * @return array<string,mixed> The updated Minutes object data
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function reject(string $minutesId, string $comment, string $userId): array;

	/**
	 * Transition a Minutes object to the next lifecycle state (server-side enforcement).
	 *
	 * Validates that the requested transition follows the allowed sequence
	 * (draft → review → approved → signed → published). Populates server-side
	 * fields: approvedAt for the "approved" transition and signedBy (with the
	 * authenticated user's display name) for the "approved" and "signed" transitions.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $newLifecycle The target lifecycle state
	 * @param string $displayName Display name of the authenticated user (from server session)
	 *
	 * @throws InvalidArgumentException When the Minutes object is not found or the transition is invalid
	 * @throws RuntimeException When OpenRegister is not available
	 *
	 * @return array<string,mixed> The updated Minutes object data
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-1
	 */
	public function transition(string $minutesId, string $newLifecycle, string $displayName): array;
}//end interface
