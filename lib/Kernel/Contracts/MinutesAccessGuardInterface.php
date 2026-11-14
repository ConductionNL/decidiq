<?php

/**
 * Contract of MinutesAccessGuard
 *
 * The public surface of MinutesAccessGuard that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of MinutesAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MinutesAccessGuardInterface {

	/**
	 * Require chair, secretary, or NC admin authority on a Minutes record.
	 *
	 * Resolves the associated meeting via the minutes relations map and checks
	 * participant records for a chair or secretary role, mirroring
	 * AgendaController.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return JSONResponse|null Null if authorised, a 401/403 JSONResponse otherwise.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-1
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function requireChairOrAdmin(string $minutesId): ?JSONResponse;

	/**
	 * Require that the caller is a participant of the meeting linked to the
	 * Minutes record (any role), a chair/secretary, or an NC admin.
	 *
	 * Used by the correction-suggestion endpoint: per the resolution-minutes
	 * spec, every meeting participant may suggest corrections during review,
	 * while resolution of suggestions stays chair/secretary-gated. Fails
	 * CLOSED: an unresolvable meeting yields 403 for non-admins.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return JSONResponse|null Null if authorised, a 401/403 JSONResponse otherwise.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function requireParticipant(string $minutesId): ?JSONResponse;
}//end interface
