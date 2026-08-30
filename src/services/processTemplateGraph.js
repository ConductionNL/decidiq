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
	const errors = []
	const sm = template?.stateMachine
	if (!sm || typeof sm !== 'object') {
		return { valid: false, errors: ['stateMachine is required.'] }
	}

	const stateNames = new Set(
		(Array.isArray(sm.states) ? sm.states : [])
			.map((s) => s?.name)
			.filter((n) => typeof n === 'string' && n !== ''),
	)
	if (stateNames.size === 0) {
		errors.push('states[] must declare at least one named state.')
	}

	const initialState = template?.initialState
	if (typeof initialState !== 'string' || initialState === '') {
		errors.push('initialState is required.')
	} else if (stateNames.size > 0 && !stateNames.has(initialState)) {
		errors.push(`initialState '${initialState}' is not declared in states[].`)
	}

	const inbound = new Set()
	const outbound = new Set()
	for (const t of Array.isArray(sm.transitions) ? sm.transitions : []) {
		const from = t?.from
		const to = t?.to
		if (
			typeof from !== 'string'
			|| from === ''
			|| typeof to !== 'string'
			|| to === ''
		) {
			errors.push('Each transition must declare non-empty from and to states.')
			continue
		}
		if (!stateNames.has(from)) {
			errors.push(`Transition references dangling from-state '${from}'.`)
		}
		if (!stateNames.has(to)) {
			errors.push(`Transition references dangling to-state '${to}'.`)
		}
		outbound.add(from)
		inbound.add(to)
		for (const g of Array.isArray(t.guards) ? t.guards : []) {
			if (!KNOWN_GUARDS.includes(g)) {
				errors.push(`Unknown guard token '${g}'.`)
			}
		}
	}

	for (const name of stateNames) {
		if (!inbound.has(name) && !outbound.has(name) && name !== initialState) {
			errors.push(
				`State '${name}' is unreachable: no transitions reference it.`,
			)
		}
	}

	return { valid: errors.length === 0, errors }
}
