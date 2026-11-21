// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure state-machine graph validation for process templates. Mirrors the
// authoritative server-side checks in ProcessTemplateService::validateStateMachine()
// for fast editor feedback; the server is always the authority on save. Kept
// dependency-free so it is unit-testable in isolation (no pinia / NC imports).
//
// @spec openspec/specs/process-configuration/spec.md

/**
 * Guard tokens recognised by both the editor and the backend.
 *
 * @type {string[]}
 */
export const KNOWN_GUARDS = [
	'quorum_met',
	'chair_only',
	'all_amendments_resolved',
	'legal_review_complete',
]

/**
 * Validate a state-machine transition graph (pure; mirrors the server checks).
 *
 * @param {object} template The template payload ({ initialState, stateMachine })
 * @return {{ valid: boolean, errors: string[] }} Validation result
 * @spec openspec/specs/process-configuration/spec.md
 */
export function validateStateMachineGraph(template) {
	const sm = template?.stateMachine
	if (!sm || typeof sm !== 'object') {
		return { valid: false, errors: ['stateMachine is required.'] }
	}

	const graph = {
		names: declaredStateNames(sm),
		initialState: template?.initialState,
		inbound: new Set(),
		outbound: new Set(),
		errors: [],
	}
	checkStatesDeclared(graph)
	checkInitialState(graph)
	for (const t of listOf(sm.transitions)) {
		checkTransition(graph, t)
	}
	checkReachability(graph)

	return { valid: graph.errors.length === 0, errors: graph.errors }
}

/**
 * The value when it is a list, otherwise an empty list.
 *
 * @param {unknown} value Any value.
 * @return {Array} The list.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * Whether a value is a non-empty string.
 *
 * @param {unknown} value Any value.
 * @return {boolean} True for a usable state name.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function isStateName(value) {
	return typeof value === 'string' && value !== ''
}

/**
 * The named states the state machine declares.
 *
 * @param {object} sm The state machine.
 * @return {Set<string>} The state names.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function declaredStateNames(sm) {
	return new Set(
		listOf(sm.states)
			.map((s) => s?.name)
			.filter((n) => typeof n === 'string' && n !== ''),
	)
}

/**
 * At least one named state must be declared.
 *
 * @param {object} graph The graph being checked: declared names, initial state, inbound and outbound sets and the errors, changed in place.
 * @return {void}
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function checkStatesDeclared(graph) {
	if (graph.names.size === 0) {
		graph.errors.push('states[] must declare at least one named state.')
	}
}

/**
 * The initial state must be given and, when states are declared, be one of them.
 *
 * @param {object} graph The graph being checked: declared names, initial state, inbound and outbound sets and the errors, changed in place.
 * @return {void}
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function checkInitialState(graph) {
	const { names, initialState, errors } = graph
	if (!isStateName(initialState)) {
		errors.push('initialState is required.')
	} else if (names.size > 0 && !names.has(initialState)) {
		errors.push(`initialState '${initialState}' is not declared in states[].`)
	}
}

/**
 * Check one transition: both ends named and declared, and every guard known. The ends of a named transition count towards reachability.
 *
 * @param {object} graph The graph being checked: declared names, initial state, inbound and outbound sets and the errors, changed in place.
 * @param {object} t The transition.
 * @return {void}
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function checkTransition(graph, t) {
	const from = t?.from
	const to = t?.to
	if (!isStateName(from) || !isStateName(to)) {
		graph.errors.push(
			'Each transition must declare non-empty from and to states.',
		)
		return
	}
	if (!graph.names.has(from)) {
		graph.errors.push(`Transition references dangling from-state '${from}'.`)
	}
	if (!graph.names.has(to)) {
		graph.errors.push(`Transition references dangling to-state '${to}'.`)
	}
	graph.outbound.add(from)
	graph.inbound.add(to)
	checkGuards(graph, t.guards)
}

/**
 * Every guard token must be one the editor and the backend know.
 *
 * @param {object} graph The graph being checked: declared names, initial state, inbound and outbound sets and the errors, changed in place.
 * @param {unknown} guards The transition guards.
 * @return {void}
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function checkGuards(graph, guards) {
	for (const g of listOf(guards)) {
		if (!KNOWN_GUARDS.includes(g)) {
			graph.errors.push(`Unknown guard token '${g}'.`)
		}
	}
}

/**
 * Every declared state other than the initial one must appear in a transition.
 *
 * @param {object} graph The graph being checked: declared names, initial state, inbound and outbound sets and the errors, changed in place.
 * @return {void}
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
function checkReachability(graph) {
	const { names, inbound, outbound, initialState, errors } = graph
	for (const name of names) {
		if (!inbound.has(name) && !outbound.has(name) && name !== initialState) {
			errors.push(
				`State '${name}' is unreachable: no transitions reference it.`,
			)
		}
	}
}
