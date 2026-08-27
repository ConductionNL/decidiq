<?php

/**
 * Decidiq Process Template Service
 *
 * Manages process templates (the state machine, default voting rule, and quorum
 * policy a governance body follows) as OpenRegister objects, validates their
 * transition graph server-side (fail closed), and resolves a body's assigned
 * template into the policy/voting-rule shapes the lifecycle guards and voting
 * round-open path consume.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\Decidiq\Lifecycle\ProcessTemplatePolicyResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for process-template management and template-driven policy resolution.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplateService {

	/**
	 * Recognised guard tokens a transition may declare. An unknown token is a
	 * validation error (fail closed) — typos never silently disable a guard.
	 *
	 * @var string[]
	 */
	public const KNOWN_GUARDS = StateMachineValidator::KNOWN_GUARDS;

	/**
	 * Constructor for ProcessTemplateService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ProcessTemplatePolicyResolver $resolver Pure template -> guard policy translator
	 * @param StateMachineValidator $validator Pure transition-graph validator
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ProcessTemplatePolicyResolver $resolver,
		private readonly StateMachineValidator $validator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve OpenRegister ObjectService lazily.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return object The OpenRegister ObjectService
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $row The ObjectService row (ObjectEntity or array)
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()

	/**
	 * List all process templates.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<int, array<string, mixed>> The template objects
	 */
	public function list(): array {
		// The register/schema context MUST be nested under `filters`.
		// ObjectService::prepareFindAllConfig() reads ONLY
		// $config['filters']['register'] / ['schema'] — the top-level keys this
		// call used to pass are never inspected, so currentRegister/currentSchema
		// stayed null and MagicMapper::findAll() bailed out with
		// "called without register/schema context", returning [] after nothing
		// more than a logger->warning. HTTP 200, zero rows, no error: the
		// template list mounted with no <li>, which Playwright reports as
		// `hidden` (a zero-height box) rather than absent.
		//
		// `register`/`schema` are in MagicSearchHandler::getReservedParams(), so
		// they are stripped before applyObjectFilters() and cannot leak into the
		// WHERE clause as bogus property filters. Compare get() below, which
		// works because it passes them as TYPED PARAMETERS to find().
		$rows = $this->objectService()->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'process-template',
				],
				'limit' => 1000,
			]
		);

		$out = [];
		foreach ((array)$rows as $row) {
			$out[] = $this->toArray(row: $row);
		}

		return $out;
	}//end list()

	/**
	 * Get a single process template by UUID.
	 *
	 * @param string $templateId The template UUID
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The template object, or null when not found
	 */
	public function get(string $templateId): ?array {
		$entity = $this->objectService()->find(id: $templateId, register: 'decidiq', schema: 'process-template');
		if ($entity === null) {
			return null;
		}

		return $this->toArray(row: $entity);
	}//end get()

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
	public function create(array $template): array {
		$this->assertValidStateMachine(template: $template);

		// Created templates are never built-in; only seeds may be built-in.
		$template['builtIn'] = false;

		$saved = $this->objectService()->saveObject(register: 'decidiq', schema: 'process-template', object: $template);
		return $this->toArray(row: $saved);
	}//end create()

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
	public function update(string $templateId, array $template): array {
		$existing = $this->get(templateId: $templateId);
		if ($existing === null) {
			throw new RuntimeException("Process template '$templateId' not found.");
		}

		if (($existing['builtIn'] ?? false) === true) {
			throw new RuntimeException('Built-in templates are read-only; duplicate it to customise.');
		}

		$this->assertValidStateMachine(template: $template);

		// Preserve identity + the not-built-in invariant.
		$template['id'] = $templateId;
		$template['builtIn'] = false;

		$saved = $this->objectService()->saveObject(register: 'decidiq', schema: 'process-template', object: $template);
		return $this->toArray(row: $saved);
	}//end update()

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
	public function duplicate(string $templateId, ?string $newName = null): array {
		$source = $this->get(templateId: $templateId);
		if ($source === null) {
			throw new RuntimeException("Process template '$templateId' not found.");
		}

		$copy = $source;
		unset($copy['id'], $copy['uuid'], $copy['@self']);
		$copy['builtIn'] = false;
		$copy['name'] = ($newName ?? (($source['name'] ?? 'Template') . ' (copy)'));

		$saved = $this->objectService()->saveObject(register: 'decidiq', schema: 'process-template', object: $copy);
		return $this->toArray(row: $saved);
	}//end duplicate()

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
	public function delete(string $templateId): void {
		$existing = $this->get(templateId: $templateId);
		if ($existing === null) {
			throw new RuntimeException("Process template '$templateId' not found.");
		}

		if (($existing['builtIn'] ?? false) === true) {
			throw new RuntimeException('Built-in templates are read-only and cannot be deleted.');
		}

		$this->objectService()->deleteObject(uuid: $templateId, register: 'decidiq', schema: 'process-template');

	}//end delete()

	/**
	 * Validate a template's state-machine transition graph (fail closed).
	 *
	 * Rejects: empty states; a transition whose from/to references a state not
	 * declared in states[] (dangling); a state with no inbound and no outbound
	 * transition that is not the declared initialState (unreachable); an
	 * unrecognised guard token.
	 *
	 * @param array<string, mixed> $template The template payload
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array{valid: bool, errors: string[]} Validation result with human-readable errors
	 */
	public function validateStateMachine(array $template): array {
		return $this->validator->validate(template: $template);
	}//end validateStateMachine()

	/**
	 * Assert the template's state machine is valid, throwing on failure.
	 *
	 * @param array<string, mixed> $template The template payload
	 *
	 * @throws InvalidArgumentException When the graph is invalid
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return void
	 */
	private function assertValidStateMachine(array $template): void {
		$result = $this->validateStateMachine(template: $template);
		if ($result['valid'] === false) {
			throw new InvalidArgumentException('Invalid state machine: ' . implode(' ', $result['errors']));
		}

	}//end assertValidStateMachine()

	/**
	 * Resolve a governance body's assigned template into the guard policy
	 * override shape, or null when the body has no usable template (caller then
	 * falls back to the built-in hardcoded domain policy — fail-safe).
	 *
	 * @param string|null $governanceBodyId The governance body UUID, or null
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The guard policy override, or null to fall back
	 */
	public function resolvePolicyForBody(?string $governanceBodyId): ?array {
		$template = $this->loadBodyTemplate(governanceBodyId: $governanceBodyId);
		return $this->resolver->resolve(template: $template);
	}//end resolvePolicyForBody()

	/**
	 * Resolve a governance body's template default voting rule, or null.
	 *
	 * @param string|null $governanceBodyId The governance body UUID, or null
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array{voteThreshold?: string, abstentionHandling?: string, tieBreakRule?: string}|null
	 */
	public function resolveVotingRuleForBody(?string $governanceBodyId): ?array {
		$template = $this->loadBodyTemplate(governanceBodyId: $governanceBodyId);
		return $this->resolver->resolveVotingRule(template: $template);
	}//end resolveVotingRuleForBody()

	/**
	 * Load the process-template object assigned to a governance body.
	 *
	 * Resolution: body.processTemplate is an identifier — first try it as a
	 * built-in slug, then as a UUID. Returns null (fail-soft) on any miss so the
	 * caller reverts to built-in domain behaviour.
	 *
	 * @param string|null $governanceBodyId The governance body UUID, or null
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The template object, or null
	 */
	private function loadBodyTemplate(?string $governanceBodyId): ?array {
		if ($governanceBodyId === null || $governanceBodyId === '') {
			return null;
		}

		try {
			$bodyEntity = $this->objectService()->find(id: $governanceBodyId, register: 'decidiq', schema: 'governance-body');
			if ($bodyEntity === null) {
				return null;
			}

			$body = $this->toArray(row: $bodyEntity);
			$templateRef = ($body['processTemplate'] ?? null);
			if (is_string($templateRef) === false || $templateRef === '') {
				return null;
			}

			return $this->loadTemplateByRef(ref: $templateRef);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: body template resolution skipped', ['error' => $e->getMessage()]);
			return null;
		}

	}//end loadBodyTemplate()

	/**
	 * Load a template by an identifier that may be a built-in slug or a UUID.
	 *
	 * @param string $ref The processTemplate identifier
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The template object, or null
	 */
	private function loadTemplateByRef(string $ref): ?array {
		// Try the slug first (built-in catalogue ids such as 'association-alv').
		// Register/schema nested under `filters` — see list() above for why the
		// top-level keys silently yielded zero rows.
		$rows = $this->objectService()->findAll(
			[
				'limit' => 1,
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'process-template',
					'slug' => $ref,
				],
			]
		);

		foreach ((array)$rows as $row) {
			return $this->toArray(row: $row);
		}

		// Fall back to a direct UUID lookup.
		$entity = $this->objectService()->find(id: $ref, register: 'decidiq', schema: 'process-template');
		if ($entity === null) {
			return null;
		}

		return $this->toArray(row: $entity);
	}//end loadTemplateByRef()
}//end class
