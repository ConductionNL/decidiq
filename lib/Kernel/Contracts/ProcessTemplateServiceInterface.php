<?php

/**
 * Contract of ProcessTemplateService
 *
 * The public surface of ProcessTemplateService that other components call, so they depend
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
 * Contract of ProcessTemplateService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProcessTemplateServiceInterface {

	/**
	 * Create a process template after validating its state machine.
	 *
	 * @param array<string, mixed> $template The template payload
	 *
	 * @throws InvalidArgumentException When the state-machine graph is invalid (fail closed)
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed> The created template object
	 */
	public function create(array $template): array;

	/**
	 * Delete a process template. Built-in templates are read-only and refused.
	 *
	 * @param string $templateId The template UUID
	 *
	 * @throws RuntimeException When the template is built-in (read-only) or missing
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return void
	 */
	public function delete(string $templateId): void;

	/**
	 * Duplicate an existing template into a new, editable copy.
	 *
	 * The copy clears `builtIn` and the OR identity fields so OpenRegister mints
	 * a fresh object; the original is never touched.
	 *
	 * @param string $templateId The template UUID to duplicate
	 * @param string|null $newName Optional name for the copy (defaults to "<name> (copy)")
	 *
	 * @throws RuntimeException When the source template is missing
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed> The created copy
	 */
	public function duplicate(string $templateId, ?string $newName = null): array;

	/**
	 * Get a single process template by UUID.
	 *
	 * @param string $templateId The template UUID
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The template object, or null when not found
	 */
	public function get(string $templateId): ?array;

	/**
	 * List all process templates.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<int, array<string, mixed>> The template objects
	 */
	public function list(): array;

	/**
	 * Update an existing process template after validating its state machine.
	 *
	 * Built-in templates are read-only — an attempt to update one is refused.
	 *
	 * @param string $templateId The template UUID
	 * @param array<string, mixed> $template The full template payload to persist
	 *
	 * @throws InvalidArgumentException When the state-machine graph is invalid (fail closed)
	 * @throws RuntimeException When the template is built-in (read-only) or missing
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed> The updated template object
	 */
	public function update(string $templateId, array $template): array;

	/**
	 * Validate a template's state-machine transition graph (fail closed).
	 *
	 * The graph rules are OpenRegister's (LifecycleTransitionsValidator, the entry
	 * point for a lifecycle kept as data): empty states, a missing or undeclared
	 * initial state, malformed or dangling transitions, a declared state no
	 * transition touches (unreachable) and a guard token outside KNOWN_GUARDS.
	 * Two template-shape rules stay here: stateMachine must be an object, and a
	 * transition names ONE from-state, because ProcessTemplatePolicyResolver reads
	 * a single from-state per edge and would skip a list (and its chair_only).
	 * Without OpenRegister's validator the template is refused, never accepted.
	 *
	 * @param array<string, mixed> $template The template payload
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array{valid: bool, errors: string[]} Validation result with human-readable errors
	 */
	public function validateStateMachine(array $template): array;
}//end interface
