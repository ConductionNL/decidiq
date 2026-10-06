// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A proxy holder casts the vote they were given from the voting round panel
// (#1377, capability matrix row vot-10).
//
// @spec openspec/specs/voting-system/spec.md

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	nextCastTarget,
	NO_PROXIES,
	proxiesPath,
	proxyCastFields,
	readProxies,
} from '../../src/utils/proxyVoting.js'

const here = dirname(fileURLToPath(import.meta.url))

describe('proxiesPath', () => {
	it("reads the round's proxy endpoint", () => {
		expect(proxiesPath('r-1')).toBe('/apps/decidiq/api/voting-rounds/r-1/proxy')
	})
})

describe('readProxies', () => {
	it('keeps the held proxies and the grant, dropping malformed entries', () => {
		expect(
			readProxies({
				participantId: 'me',
				held: [
					{ participantId: 'd-1', displayName: 'Anna' },
					{ participantId: 'd-2' },
					{ displayName: 'no id' },
					null,
				],
				granted: 'h-1',
			}),
		).toEqual({
			held: [
				{ participantId: 'd-1', displayName: 'Anna' },
				{ participantId: 'd-2', displayName: 'd-2' },
			],
			granted: 'h-1',
		})
	})

	it('answers nothing held for a missing or malformed answer', () => {
		expect(readProxies(null)).toEqual({ ...NO_PROXIES })
		expect(readProxies({ held: 'x', granted: 3 })).toEqual({ ...NO_PROXIES })
	})
})

describe('proxyCastFields', () => {
	it("casts the user's own vote without a delegator", () => {
		expect(proxyCastFields('')).toEqual({ isProxy: false, delegatorId: null })
	})

	it("casts on the delegator's behalf with one", () => {
		expect(proxyCastFields('d-1')).toEqual({ isProxy: true, delegatorId: 'd-1' })
	})
})

describe('nextCastTarget', () => {
	const held = [{ participantId: 'd-1' }, { participantId: 'd-2' }]

	it("offers the user's own vote first", () => {
		expect(nextCastTarget(false, held, [])).toBe('')
	})

	it('then each proxy not yet used', () => {
		expect(nextCastTarget(true, held, [])).toBe('d-1')
		expect(nextCastTarget(true, held, ['d-1'])).toBe('d-2')
		expect(nextCastTarget(true, held, ['d-1', 'd-2'])).toBe('')
	})
})

describe('VotingRoundPanel source', () => {
	const source = readFileSync(
		resolve(here, '../../src/components/VotingRoundPanel.vue'),
		'utf8',
	)

	it("no longer hard-codes every cast as the user's own vote", () => {
		expect(source).not.toMatch(/isProxy:\s*false/)
		expect(source).toMatch(/proxyCastFields\(this\.castingFor\)/)
	})
})
