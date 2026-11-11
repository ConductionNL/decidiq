// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Deleting a process template from the admin store: the request it sends,
// the refresh after success, and the error it keeps on a refusal or a
// network failure. Pinned before the action was split into smaller steps.
//
// @spec openspec/specs/process-configuration/spec.md

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useProcessTemplatesStore } from '../../src/store/modules/processTemplates.js'

describe('process templates store: deleteTemplate', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useProcessTemplatesStore()
		store.error = 'stale'
		store.fetchTemplates = vi.fn().mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	it('DELETEs the encoded template and refreshes on success', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok: true, status: 204 })
		await expect(store.deleteTemplate('a/b c')).resolves.toBe(true)
		const [url, options] = globalThis.fetch.mock.calls[0]
		expect(url).toContain('/apps/decidiq/api/process-templates/a%2Fb%20c')
		expect(options.method).toBe('DELETE')
		expect(options.headers).toHaveProperty('requesttoken')
		expect(store.fetchTemplates).toHaveBeenCalledTimes(1)
		expect(store.error).toBe('')
	})

	it('keeps the server message, or the status, on a refusal', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({
			ok: false,
			status: 409,
			json: async () => ({ message: 'Template in use' }),
		})
		await expect(store.deleteTemplate('t1')).resolves.toBe(false)
		expect(store.error).toBe('Template in use')

		globalThis.fetch = vi.fn().mockResolvedValueOnce({
			ok: false,
			status: 500,
			json: async () => {
				throw new Error('not json')
			},
		})
		await expect(store.deleteTemplate('t1')).resolves.toBe(false)
		expect(store.error).toBe('Delete failed (500)')
		expect(store.fetchTemplates).not.toHaveBeenCalled()
	})

	it('keeps the failure message when the request or the refresh throws', async () => {
		globalThis.fetch = vi.fn().mockRejectedValueOnce(new Error('offline'))
		await expect(store.deleteTemplate('t1')).resolves.toBe(false)
		expect(store.error).toBe('offline')

		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok: true, status: 204 })
		store.fetchTemplates = vi.fn().mockRejectedValue('boom')
		await expect(store.deleteTemplate('t1')).resolves.toBe(false)
		expect(store.error).toBe('boom')
	})
})
