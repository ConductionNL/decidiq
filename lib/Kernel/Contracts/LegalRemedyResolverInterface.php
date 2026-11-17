<?php

/**
 * Contract of LegalRemedyResolver
 *
 * The public surface of LegalRemedyResolver that other components call, so they depend
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

/**
 * Contract of LegalRemedyResolver.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LegalRemedyResolverInterface {

	/**
	 * Refuse a decision type that does not say how its decisions are contested.
	 *
	 * @param array<string, mixed> $type The decision type about to be published.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the declaration is missing or malformed.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function assertPublishable(array $type): void;

	/**
	 * Stamp the clause onto a decision, leaving an already-stamped one alone.
	 *
	 * Already-stamped is left alone on purpose: re-resolving would quietly
	 * rewrite what a decision told somebody, which is the whole thing this
	 * resolver exists to prevent.
	 *
	 * @param array<string, mixed> $decision The decision being taken.
	 * @param array<string, mixed> $type Its decision type.
	 *
	 * @return array<string, mixed> The decision, carrying its clause.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function stamp(array $decision, array $type): array;
}//end interface
