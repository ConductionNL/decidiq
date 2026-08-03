<?php

/**
 * Decidesk Process Template Service
 *
 * Manages process templates (the state machine, default voting rule, and quorum
 * policy a governance body follows) as OpenRegister objects, validates their
 * transition graph server-side (fail closed), and resolves a body's assigned
 * template into the policy/voting-rule shapes the lifecycle guards and voting
 * round-open path consume.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use InvalidArgumentException;
use OCA\Decidesk\Lifecycle\ProcessTemplatePolicyResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for process-template management and template-driven policy resolution.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplateService
{

    /**
     * Recognised guard tokens a transition may declare. An unknown token is a
     * validation error (fail closed) — typos never silently disable a guard.
     *
     * @var string[]
     */
    public const KNOWN_GUARDS = [
        'quorum_met',
        'chair_only',
        'all_amendments_resolved',
        'legal_review_complete',
    ];

    /**
     * Constructor for ProcessTemplateService.
     *
     * @param ContainerInterface            $container The DI container (lazy-loads OpenRegister's ObjectService)
     * @param LoggerInterface               $logger    The logger
     * @param ProcessTemplatePolicyResolver $resolver  Pure template -> guard policy translator
     *
     * @spec openspec/specs/process-configuration/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ProcessTemplatePolicyResolver $resolver,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService lazily.
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return object The OpenRegister ObjectService
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

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
    private function toArray(mixed $row): array
    {
        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
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
    public function list(): array
    {
        $rows = $this->objectService()->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'process-template',
                'limit'    => 1000,
            ]
        );

        $out = [];
        foreach ((array) $rows as $row) {
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
    public function get(string $templateId): ?array
    {
        $entity = $this->objectService()->find(id: $templateId, register: 'decidesk', schema: 'process-template');
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
    public function create(array $template): array
    {
        $this->assertValidStateMachine(template: $template);

        // Created templates are never built-in; only seeds may be built-in.
        $template['builtIn'] = false;

        $saved = $this->objectService()->saveObject(register: 'decidesk', schema: 'process-template', object: $template);
        return $this->toArray(row: $saved);

    }//end create()

    /**
     * Update an existing process template after validating its state machine.
     *
     * Built-in templates are read-only — an attempt to update one is refused.
     *
     * @param string               $templateId The template UUID
     * @param array<string, mixed> $template   The full template payload to persist
     *
     * @throws InvalidArgumentException When the state-machine graph is invalid (fail closed)
     * @throws RuntimeException         When the template is built-in (read-only) or missing
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array<string, mixed> The updated template object
     */
    public function update(string $templateId, array $template): array
    {
        $existing = $this->get(templateId: $templateId);
        if ($existing === null) {
            throw new RuntimeException("Process template '$templateId' not found.");
        }

        if (($existing['builtIn'] ?? false) === true) {
            throw new RuntimeException('Built-in templates are read-only; duplicate it to customise.');
        }

        $this->assertValidStateMachine(template: $template);

        // Preserve identity + the not-built-in invariant.
        $template['id']      = $templateId;
        $template['builtIn'] = false;

        $saved = $this->objectService()->saveObject(register: 'decidesk', schema: 'process-template', object: $template);
        return $this->toArray(row: $saved);

    }//end update()

    /**
     * Duplicate an existing template into a new, editable copy.
     *
     * The copy clears `builtIn` and the OR identity fields so OpenRegister mints
     * a fresh object; the original is never touched.
     *
     * @param string      $templateId The template UUID to duplicate
     * @param string|null $newName    Optional name for the copy (defaults to "<name> (copy)")
     *
     * @throws RuntimeException When the source template is missing
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array<string, mixed> The created copy
     */
    public function duplicate(string $templateId, ?string $newName=null): array
    {
        $source = $this->get(templateId: $templateId);
        if ($source === null) {
            throw new RuntimeException("Process template '$templateId' not found.");
        }

        $copy = $source;
        unset($copy['id'], $copy['uuid'], $copy['@self']);
        $copy['builtIn'] = false;
        $copy['name']    = ($newName ?? (($source['name'] ?? 'Template').' (copy)'));

        $saved = $this->objectService()->saveObject(register: 'decidesk', schema: 'process-template', object: $copy);
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
    public function delete(string $templateId): void
    {
        $existing = $this->get(templateId: $templateId);
        if ($existing === null) {
            throw new RuntimeException("Process template '$templateId' not found.");
        }

        if (($existing['builtIn'] ?? false) === true) {
            throw new RuntimeException('Built-in templates are read-only and cannot be deleted.');
        }

        $this->objectService()->deleteObject(uuid: $templateId, register: 'decidesk', schema: 'process-template');

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
    public function validateStateMachine(array $template): array
    {
        $errors = [];

        $stateMachine = ($template['stateMachine'] ?? null);
        if (is_array($stateMachine) === false) {
            return ['valid' => false, 'errors' => ['stateMachine is required and must be an object.']];
        }

        $stateNames = [];
        foreach ((array) ($stateMachine['states'] ?? []) as $state) {
            if (is_array($state) === true) {
                $name = ($state['name'] ?? null);
            } else {
                $name = null;
            }

            if (is_string($name) === true && $name !== '') {
                $stateNames[$name] = true;
            }
        }

        if ($stateNames === []) {
            $errors[] = 'states[] must declare at least one named state.';
        }

        $initialState = ($template['initialState'] ?? null);
        if (is_string($initialState) === false || $initialState === '') {
            $errors[] = 'initialState is required.';
        } else if (isset($stateNames[$initialState]) === false && $stateNames !== []) {
            $errors[] = "initialState '$initialState' is not declared in states[].";
        }

        $inbound  = [];
        $outbound = [];
        foreach ((array) ($stateMachine['transitions'] ?? []) as $transition) {
            if (is_array($transition) === false) {
                $errors[] = 'Each transition must be an object.';
                continue;
            }

            $from = ($transition['from'] ?? null);
            $to   = ($transition['to'] ?? null);

            if (is_string($from) === false || $from === '' || is_string($to) === false || $to === '') {
                $errors[] = 'Each transition must declare non-empty from and to states.';
                continue;
            }

            if (isset($stateNames[$from]) === false) {
                $errors[] = "Transition references dangling from-state '$from' not declared in states[].";
            }

            if (isset($stateNames[$to]) === false) {
                $errors[] = "Transition references dangling to-state '$to' not declared in states[].";
            }

            $outbound[$from] = true;
            $inbound[$to]    = true;

            foreach ((array) ($transition['guards'] ?? []) as $guard) {
                if (in_array($guard, self::KNOWN_GUARDS, true) === false) {
                    if (is_string($guard) === true) {
                        $guardLabel = $guard;
                    } else {
                        $guardLabel = '?';
                    }

                    $errors[] = "Unknown guard token '".$guardLabel."'.";
                }
            }
        }//end foreach

        // Unreachable: a state with neither inbound nor outbound transitions that
        // is not the initial state.
        foreach (array_keys($stateNames) as $name) {
            $hasEdge = (isset($inbound[$name]) === true || isset($outbound[$name]) === true);
            if ($hasEdge === false && $name !== $initialState) {
                $errors[] = "State '$name' is unreachable: no transitions reference it.";
            }
        }

        return ['valid' => ($errors === []), 'errors' => $errors];

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
    private function assertValidStateMachine(array $template): void
    {
        $result = $this->validateStateMachine(template: $template);
        if ($result['valid'] === false) {
            throw new InvalidArgumentException('Invalid state machine: '.implode(' ', $result['errors']));
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
    public function resolvePolicyForBody(?string $governanceBodyId): ?array
    {
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
    public function resolveVotingRuleForBody(?string $governanceBodyId): ?array
    {
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
    private function loadBodyTemplate(?string $governanceBodyId): ?array
    {
        if ($governanceBodyId === null || $governanceBodyId === '') {
            return null;
        }

        try {
            $bodyEntity = $this->objectService()->find(id: $governanceBodyId, register: 'decidesk', schema: 'governance-body');
            if ($bodyEntity === null) {
                return null;
            }

            $body        = $this->toArray(row: $bodyEntity);
            $templateRef = ($body['processTemplate'] ?? null);
            if (is_string($templateRef) === false || $templateRef === '') {
                return null;
            }

            return $this->loadTemplateByRef(ref: $templateRef);
        } catch (\Throwable $e) {
            $this->logger->debug('Decidesk: body template resolution skipped', ['error' => $e->getMessage()]);
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
    private function loadTemplateByRef(string $ref): ?array
    {
        // Try the slug first (built-in catalogue ids such as 'association-alv').
        $rows = $this->objectService()->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'process-template',
                'limit'    => 1,
                'filters'  => ['slug' => $ref],
            ]
        );

        foreach ((array) $rows as $row) {
            return $this->toArray(row: $row);
        }

        // Fall back to a direct UUID lookup.
        $entity = $this->objectService()->find(id: $ref, register: 'decidesk', schema: 'process-template');
        if ($entity === null) {
            return null;
        }

        return $this->toArray(row: $entity);

    }//end loadTemplateByRef()
}//end class
