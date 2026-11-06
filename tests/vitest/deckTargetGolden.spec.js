/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `resolveTarget` in src/services/deckProjection.js over
 * seeded Deck leaf answers: the sticky default, the board list and the
 * stacks, whose titles are matched to lanes. Each case records the requested
 * URLs and the resolved target or the error message; a digest pins every
 * case and a sample stays readable.
 *
 * @spec exclude golden test of the Deck target resolution, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it, vi } from 'vitest'
import { resolveTarget } from '../../src/services/deckProjection.js'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => get(...a) },
}))

/**
 * A seeded pseudo-random generator (mulberry32).
 *
 * @param {number} seed The seed.
 *
 * @return {function(): number} Returns a float in [0, 1).
 */
function mulberry32(seed) {
	let a = seed >>> 0
	return () => {
		a = (a + 0x6d2b79f5) >>> 0
		let t = a
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

/**
 * Pick one item of a list.
 *
 * @param {function(): number} rnd The generator.
 * @param {Array} list The choices.
 *
 * @return {*} The picked item.
 */
function pick(rnd, list) {
	return list[Math.floor(rnd() * list.length)]
}

const WORDS = [
	'Done',
	'complete',
	'COMPLETED',
	'Klaar',
	'progress',
	'Doing',
	'bezig',
	'mee bezig',
	'Open',
	'to do',
	'TODO',
	'backlog',
	'te doen',
	'Ideas',
	'Review',
	'later',
	'',
	' ',
]

const DEFAULTS = [
	{ reject: true },
	{ data: null },
	{ data: {} },
	{ data: { boardId: 7 } },
	{ data: { boardId: '9', stackId: '31' } },
	{ data: { boardId: 0, stackId: 4 } },
	{ data: { stackId: 22 } },
]

const BOARDS = [
	{ reject: true },
	{ data: null },
	{ data: { results: [] } },
	{ data: { results: [{ id: 5 }, { id: 6 }] } },
	{ data: { results: [{ id: '12' }] } },
]

/**
 * Generate a stack list with titles built from lane words.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object} The stacks answer.
 */
function stacksAnswer(rnd) {
	if (rnd() < 0.1) {
		return { data: rnd() < 0.5 ? null : { results: [] } }
	}
	const n = 1 + Math.floor(rnd() * 5)
	const results = []
	for (let i = 0; i < n; i++) {
		const title =
			rnd() < 0.1
				? null
				: pick(rnd, WORDS) + (rnd() < 0.4 ? ' ' + pick(rnd, WORDS) : '')
		results.push({ id: 100 + i, title })
	}
	return { data: { results } }
}

/**
 * Answer one URL from the case's leaf answers.
 *
 * @param {object} leaf The answers keyed default, boards and stacks.
 * @param {string} url The requested URL.
 *
 * @return {Promise<object>} The answer.
 */
function answer(leaf, url) {
	let key = 'boards'
	if (url.includes('/deck/default/')) {
		key = 'default'
	} else if (url.endsWith('/stacks')) {
		key = 'stacks'
	}
	const a = leaf[key]
	return a.reject === true
		? Promise.reject(new Error(key + ' failed'))
		: Promise.resolve({ data: a.data })
}

/**
 * Run resolveTarget over the generated cases of one seed.
 *
 * @param {number} seed The seed.
 *
 * @return {Promise<Array>} One record per case.
 */
async function cases(seed) {
	const rnd = mulberry32(seed)
	const out = []
	for (let i = 0; i < 250; i++) {
		const leaf = {
			default: pick(rnd, DEFAULTS),
			boards: pick(rnd, BOARDS),
			stacks: stacksAnswer(rnd),
		}
		const schema = pick(rnd, ['decision', 'a b/c', 'meeting'])
		const urls = []
		get.mockReset()
		get.mockImplementation((url) => {
			urls.push(url)
			return answer(leaf, url)
		})
		let result
		try {
			result = await resolveTarget(schema)
		} catch (e) {
			result = { error: e.message }
		}
		out.push({ leaf, schema, urls, result })
	}
	return out
}

describe('resolveTarget golden output', () => {
	it('keeps its target over seeded leaf answers', async () => {
		const all = []
		for (const seed of [3, 11, 2026]) {
			all.push(...(await cases(seed)))
		}
		const digest = createHash('sha256').update(JSON.stringify(all)).digest('hex')
		expect({ digest, sample: all.slice(0, 20) }).toMatchSnapshot()
	})
})
