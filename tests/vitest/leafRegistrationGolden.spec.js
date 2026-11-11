/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the two integration-leaf registrars: a live registry, a
 * duplicate registration, a registry that is not installed yet, an already
 * installed queue stub and a missing global. Pinned before both moved onto
 * one shared registration helper.
 *
 * @spec exclude golden test of the leaf registration, no behaviour of its own
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	approvalChainLeafDescriptor,
	registerApprovalChainLeaf,
} from '../../src/integrations/registerApprovalChainLeaf.js'
import {
	decisionsLeafDescriptor,
	registerDecisionsLeaf,
} from '../../src/integrations/registerDecisionsLeaf.js'

const LEAVES = [
	{
		name: 'approval chain',
		register: registerApprovalChainLeaf,
		descriptor: approvalChainLeafDescriptor,
	},
	{
		name: 'decisions',
		register: registerDecisionsLeaf,
		descriptor: decisionsLeafDescriptor,
	},
]

afterEach(() => {
	vi.restoreAllMocks()
})

describe.each(LEAVES)(
	'register the $name leaf',
	({ name, register, descriptor }) => {
		it('registers live on an installed registry', () => {
			const registered = []
			const global = {
				OCA: {
					OpenRegister: {
						integrations: { register: (d) => registered.push(d) },
					},
				},
			}
			register(global)
			expect(registered).toEqual([descriptor])
		})

		it('warns instead of failing on a duplicate registration', () => {
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const error = new Error('duplicate')
			const global = {
				OCA: {
					OpenRegister: {
						integrations: {
							register: () => {
								throw error
							},
						},
					},
				},
			}
			expect(() => register(global)).not.toThrow()
			expect(warn).toHaveBeenCalledWith(
				`[decidiq] ${name} leaf already registered`,
				error,
			)
		})

		it('installs a queue stub when no registry is there yet', () => {
			for (const global of [
				{},
				{ OCA: {} },
				{ OCA: { OpenRegister: { integrations: null } } },
			]) {
				register(global)
				const stub = global.OCA.OpenRegister.integrations
				expect(stub._queue).toEqual([descriptor])
				stub.register('next')
				expect(stub._queue).toEqual([descriptor, 'next'])
			}
		})

		it('queues on an existing stub and on a registry without register()', () => {
			const queued = {
				_queue: ['earlier'],
				register(entry) {
					this._queue.push(entry)
				},
			}
			register({ OCA: { OpenRegister: { integrations: queued } } })
			expect(queued._queue).toEqual(['earlier', descriptor])
			const odd = { register: 'not a function', calls: [] }
			expect(() =>
				register({ OCA: { OpenRegister: { integrations: odd } } }),
			).toThrow(TypeError)
		})

		it('falls back to window and does nothing without one', () => {
			const registered = []
			vi.stubGlobal('window', {
				OCA: {
					OpenRegister: {
						integrations: { register: (d) => registered.push(d) },
					},
				},
			})
			register()
			expect(registered).toEqual([descriptor])
			vi.unstubAllGlobals()
			expect(() => register(undefined)).not.toThrow()
		})
	},
)
