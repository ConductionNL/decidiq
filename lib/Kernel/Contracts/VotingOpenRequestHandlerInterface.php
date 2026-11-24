<?php

/**
 * Contract of VotingOpenRequestHandler
 *
 * The public surface of VotingOpenRequestHandler that other components call, so they depend
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
 * Contract of VotingOpenRequestHandler.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VotingOpenRequestHandlerInterface {

	/**
	 * Parse the request body and open the round.
	 *
	 * For subjectType=amendment, motionId carries the AMENDMENT UUID; the
	 * parliamentary ordering rules (amendments before the motion, chair-set order)
	 * are enforced server-side by VotingService (fail closed).
	 *
	 * @param array<string, mixed> $params The request parameters
	 *
	 * @return JSONResponse 201 with the created round, or 400 with the validation message
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	public function handle(array $params): JSONResponse;
}//end interface
