<?php

/**
 * Contract of ProcessTemplatePolicyResolver
 *
 * The public surface of ProcessTemplatePolicyResolver that other components call, so they depend
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

/**
 * Contract of ProcessTemplatePolicyResolver.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProcessTemplatePolicyResolverInterface {

	/**
	 * Translate a process-template object array into the guard policy shape
	 * `{quorumEnforced, chairOnlyTransitions, allowDecideWithoutVote}`.
	 *
	 * Returns null when the template lacks a usable state machine, so the caller
	 * reverts to the built-in hardcoded domain policy. A malformed template can
	 * therefore never *loosen* a guard — it only reverts to default-deny.
	 *
	 * @param array<string, mixed>|null $template The process-template object array, or null when none assigned
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The guard policy override, or null to fall back
	 */
	public function resolve(?array $template): ?array;

	/**
	 * Extract the template's default voting rule, or null when not configured.
	 *
	 * @param array<string, mixed>|null $template The process-template object array, or null
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array{voteThreshold?: string, abstentionHandling?: string, tieBreakRule?: string}|null
	 */
	public function resolveVotingRule(?array $template): ?array;
}//end interface
