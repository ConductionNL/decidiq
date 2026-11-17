<?php

/**
 * Contract of DecisionCompetenceGuard
 *
 * The public surface of DecisionCompetenceGuard that other components call, so they depend
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

use OCA\Decidiq\Exception\DecisionCompetenceRefusedException;

/**
 * Contract of DecisionCompetenceGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionCompetenceGuardInterface {

	/**
	 * Refuse a decision its body is not competent to take.
	 *
	 * A decision without a type is not governed by a decision type and is left
	 * to the guards that already apply to it.
	 *
	 * @param array<string, mixed> $decision The decision as it is about to be saved.
	 *
	 * @return void
	 *
	 * @throws DecisionCompetenceRefusedException When the body is not competent, no competent body
	 *                                            can be established, or competence cannot be checked.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
	 */
	public function assertCompetent(array $decision): void;
}//end interface
