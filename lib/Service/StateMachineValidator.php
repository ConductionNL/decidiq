<?php

/**
 * Decidesk Process Template State-Machine Validator
 *
 * Validates a process template's transition graph server-side (fail closed):
 * dangling from/to references, unreachable states, malformed transitions and
 * unrecognised guard tokens. Extracted from ProcessTemplateService so graph
 * validation is a single cohesive responsibility.
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

/**
 * Pure validator for a process template's state-machine graph.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class StateMachineValidator
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
    public function validate(array $template): array
    {
        $stateMachine = ($template['stateMachine'] ?? null);
        if (is_array($stateMachine) === false) {
            return ['valid' => false, 'errors' => ['stateMachine is required and must be an object.']];
        }

        $stateNames   = $this->collectStateNames(states: (array) ($stateMachine['states'] ?? []));
        $initialState = ($template['initialState'] ?? null);

        $errors = [];
        if ($stateNames === []) {
            $errors[] = 'states[] must declare at least one named state.';
        }

        $errors = array_merge(
            $errors,
            $this->initialStateErrors(initialState: $initialState, stateNames: $stateNames)
        );

        $graph  = $this->inspectTransitions(
            transitions: (array) ($stateMachine['transitions'] ?? []),
            stateNames: $stateNames
        );
        $errors = array_merge($errors, $graph['errors']);

        // Unreachable: a state with neither inbound nor outbound transitions that
        // is not the initial state.
        $errors = array_merge(
            $errors,
            $this->unreachableStateErrors(
                stateNames: $stateNames,
                inbound: $graph['inbound'],
                outbound: $graph['outbound'],
                initialState: $initialState
            )
        );

        return ['valid' => ($errors === []), 'errors' => $errors];

    }//end validate()

    /**
     * Collect the declared, non-empty state names as a lookup map.
     *
     * @param array<int|string, mixed> $states The raw states[] entries
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array<string, true> Declared state names keyed for isset() lookup
     */
    private function collectStateNames(array $states): array
    {
        $stateNames = [];
        foreach ($states as $state) {
            $name = null;
            if (is_array($state) === true) {
                $name = ($state['name'] ?? null);
            }

            if (is_string($name) === true && $name !== '') {
                $stateNames[$name] = true;
            }
        }

        return $stateNames;

    }//end collectStateNames()

    /**
     * Validate the declared initialState against the state list.
     *
     * @param mixed               $initialState The declared initial state
     * @param array<string, true> $stateNames   Declared state names
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return string[] The initialState errors, if any
     */
    private function initialStateErrors(mixed $initialState, array $stateNames): array
    {
        if (is_string($initialState) === false || $initialState === '') {
            return ['initialState is required.'];
        }

        if (isset($stateNames[$initialState]) === false && $stateNames !== []) {
            return ["initialState '$initialState' is not declared in states[]."];
        }

        return [];

    }//end initialStateErrors()

    /**
     * Walk the transitions, collecting errors and the inbound/outbound edge maps.
     *
     * @param array<int|string, mixed> $transitions The raw transitions[] entries
     * @param array<string, true>      $stateNames  Declared state names
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array{errors: string[], inbound: array<string, true>, outbound: array<string, true>}
     */
    private function inspectTransitions(array $transitions, array $stateNames): array
    {
        $errors   = [];
        $inbound  = [];
        $outbound = [];

        foreach ($transitions as $transition) {
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

            $errors = array_merge($errors, $this->danglingStateErrors(from: $from, to: $to, stateNames: $stateNames));

            $outbound[$from] = true;
            $inbound[$to]    = true;

            $errors = array_merge($errors, $this->unknownGuardErrors(guards: (array) ($transition['guards'] ?? [])));
        }//end foreach

        return ['errors' => $errors, 'inbound' => $inbound, 'outbound' => $outbound];

    }//end inspectTransitions()

    /**
     * Report transition endpoints that are not declared in states[].
     *
     * @param string              $from       The transition source state
     * @param string              $to         The transition target state
     * @param array<string, true> $stateNames Declared state names
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return string[] The dangling-endpoint errors, if any
     */
    private function danglingStateErrors(string $from, string $to, array $stateNames): array
    {
        $errors = [];
        if (isset($stateNames[$from]) === false) {
            $errors[] = "Transition references dangling from-state '$from' not declared in states[].";
        }

        if (isset($stateNames[$to]) === false) {
            $errors[] = "Transition references dangling to-state '$to' not declared in states[].";
        }

        return $errors;

    }//end danglingStateErrors()

    /**
     * Report guard tokens that are not part of the recognised catalogue.
     *
     * @param array<int|string, mixed> $guards The transition's declared guards
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return string[] The unknown-guard errors, if any
     */
    private function unknownGuardErrors(array $guards): array
    {
        $errors = [];
        foreach ($guards as $guard) {
            if (in_array($guard, self::KNOWN_GUARDS, true) === true) {
                continue;
            }

            $guardLabel = '?';
            if (is_string($guard) === true) {
                $guardLabel = $guard;
            }

            $errors[] = "Unknown guard token '".$guardLabel."'.";
        }

        return $errors;

    }//end unknownGuardErrors()

    /**
     * Report states that no transition references and that are not the initial state.
     *
     * @param array<string, true> $stateNames   Declared state names
     * @param array<string, true> $inbound      States reached by some transition
     * @param array<string, true> $outbound     States leaving via some transition
     * @param mixed               $initialState The declared initial state
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return string[] The unreachable-state errors, if any
     */
    private function unreachableStateErrors(array $stateNames, array $inbound, array $outbound, mixed $initialState): array
    {
        $errors = [];
        foreach (array_keys($stateNames) as $name) {
            $hasEdge = (isset($inbound[$name]) === true || isset($outbound[$name]) === true);
            if ($hasEdge === false && $name !== $initialState) {
                $errors[] = "State '$name' is unreachable: no transitions reference it.";
            }
        }

        return $errors;

    }//end unreachableStateErrors()
}//end class
