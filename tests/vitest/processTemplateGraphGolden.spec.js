/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for validateStateMachineGraph: fixed templates plus seeded
 * generated ones over state, transition and guard shapes, with every
 * result snapshotted.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	KNOWN_GUARDS,
	validateStateMachineGraph,
} from '../../src/services/processTemplateGraph.js'

/**
 * A deterministic pick sequence (mulberry32).
 *
 * @param {number} seed - the start of the sequence
 * @return {Function} returns the next pick below a given bound
 */
function picker(seed) {
	let state = seed >>> 0
	return (bound) => {
		state = (state + 0x6d2b79f5) >>> 0
		let t = Math.imul(state ^ (state >>> 15), 1 | state)
		t ^= t + Math.imul(t ^ (t >>> 7), 61 | t)
		return ((t ^ (t >>> 14)) >>> 0) % bound
	}
}

const NAMES = ['draft', 'review', 'done', 'gone', '', null, 7]
const GUARDS = [
	KNOWN_GUARDS[0],
	KNOWN_GUARDS[KNOWN_GUARDS.length - 1],
	'mystery',
	'',
]

/**
 * A generated template.
 *
 * @param {number} seed - the start of the sequence
 * @return {object} the template
 */
function generated(seed) {
	const pick = picker(seed)
	const states = Array.from(
		{ length: pick(5) },
		() =>
			[{ name: NAMES[pick(NAMES.length)] }, null, 'draft', {}][
				pick(4) === 0 ? 1 + pick(3) : 0
			],
	)
	const transitions = Array.from({ length: pick(5) }, () => {
		const t = { from: NAMES[pick(NAMES.length)], to: NAMES[pick(NAMES.length)] }
		if (pick(2)) {
			t.guards = pick(4)
				? Array.from({ length: pick(3) }, () => GUARDS[pick(GUARDS.length)])
				: 'not a list'
		}
		return pick(8) ? t : null
	})
	const stateMachine = {}
	if (pick(6)) {
		stateMachine.states = pick(6) ? states : 'not a list'
	}
	if (pick(6)) {
		stateMachine.transitions = pick(6) ? transitions : {}
	}
	const template = { stateMachine }
	if (pick(5)) {
		template.initialState = [...NAMES, undefined][pick(NAMES.length + 1)]
	}
	return template
}

const TEMPLATES = {
	undefined,
	null: null,
	'no state machine': {},
	'state machine is text': { stateMachine: 'x' },
	'state machine is null': { stateMachine: null },
	'empty state machine': { stateMachine: {} },
	'valid minimal': {
		initialState: 'a',
		stateMachine: { states: [{ name: 'a' }] },
	},
	'valid chain': {
		initialState: 'a',
		stateMachine: {
			states: [{ name: 'a' }, { name: 'b' }, { name: 'c' }],
			transitions: [
				{ from: 'a', to: 'b', guards: [KNOWN_GUARDS[0]] },
				{ from: 'b', to: 'c' },
			],
		},
	},
	'initial state undeclared': {
		initialState: 'z',
		stateMachine: { states: [{ name: 'a' }] },
	},
	'initial state without states': {
		initialState: 'z',
		stateMachine: { states: [] },
	},
	'unreachable state': {
		initialState: 'a',
		stateMachine: {
			states: [{ name: 'a' }, { name: 'b' }, { name: 'c' }],
			transitions: [{ from: 'b', to: 'b' }],
		},
	},
	'dangling both ends': {
		initialState: 'a',
		stateMachine: {
			states: [{ name: 'a' }],
			transitions: [{ from: 'x', to: 'y', guards: ['nope', KNOWN_GUARDS[0]] }],
		},
	},
	'empty from and to': {
		initialState: 'a',
		stateMachine: {
			states: [{ name: 'a' }],
			transitions: [
				{ from: '', to: 'a' },
				{ from: 'a', to: '' },
				{ from: 'a' },
				{},
			],
		},
	},
	'duplicate state names': {
		initialState: 'a',
		stateMachine: {
			states: [{ name: 'a' }, { name: 'a' }, { name: 'b' }],
			transitions: [{ from: 'a', to: 'b' }],
		},
	},
}
for (let n = 0; n < 150; n++) {
	TEMPLATES['generated ' + n] = generated(n + 1)
}

describe('validateStateMachineGraph golden', () => {
	for (const [name, template] of Object.entries(TEMPLATES)) {
		it(name, () => {
			expect(validateStateMachineGraph(template)).toMatchSnapshot()
		})
	}
})
