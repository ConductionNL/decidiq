<?php

/**
 * Contract of DecisionTypeRegistry
 *
 * The public surface of DecisionTypeRegistry that other components call, so they depend
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
 * Contract of DecisionTypeRegistry.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionTypeRegistryInterface {

	/**
	 * App-config key holding the decisionType vocabulary as a JSON array.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'decision_types';

	/**
	 * The shipped decision-type seed.
	 *
	 * This is the bootstrap vocabulary, not a validation home: it is written
	 * into app config once by the SeedDecisionTypes repair step and consulted
	 * at runtime only while that row is still absent. It carries every type a
	 * fleet caller sends today: dossiq raises `contract-renewal`,
	 * `report-adoption`, `advice`, `bezwaar-decision` and `woo-decision`;
	 * stackiq raises `contract` and `contract-renewal`. The parity test
	 * (DecisionIntegrationServiceTest) pins that coverage.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_TYPES = [
		'motion',
		'amendment',
		'resolution',
		'contract',
		'contract-renewal',
		'report-adoption',
		'appointment',
		'management-point',
		'policy',
		'meeting-outcome',
		'advice',
		'bezwaar-decision',
		'woo-decision',
	];

	/**
	 * Return the configured decisionType vocabulary.
	 *
	 * Reads the stored `decision_types` app-config array, keeping only
	 * non-empty strings. Falls back to the shipped seed when the row is
	 * absent or holds nothing usable, so validation never fails closed on
	 * the entire vocabulary because a seed has not run yet.
	 *
	 * @return list<string> The valid decisionType values
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function getTypes(): array;

	/**
	 * Replace the vocabulary with the list an administrator saved.
	 *
	 * Every entry is a lowercase slug, the form callers send. A list with no
	 * usable entry is refused rather than stored: an empty stored list reads
	 * back as the shipped seed, so saving it would look like success while
	 * bringing back every type the administrator had just removed.
	 *
	 * @param array<int|string,mixed> $types The submitted types
	 *
	 * @return array<int,string> The stored vocabulary
	 *
	 * @throws \InvalidArgumentException When an entry is not a slug, or nothing is left
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function setTypes(array $types): array;
}//end interface
