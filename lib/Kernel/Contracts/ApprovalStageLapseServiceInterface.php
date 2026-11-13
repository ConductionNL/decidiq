<?php

/**
 * Contract of ApprovalStageLapseService
 *
 * The public surface of ApprovalStageLapseService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ApprovalStageLapseService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalStageLapseServiceInterface {

	/**
	 * Sweep every waiting stage.
	 *
	 * @param DateTimeImmutable|null $now The clock; the real one when null.
	 *
	 * @return array{lapsed: int, substitutesAsked: int} What the sweep did.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-017)
	 */
	public function sweep(?DateTimeImmutable $now = null): array;
}//end interface
