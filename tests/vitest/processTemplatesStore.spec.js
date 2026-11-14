// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The process-template admin store's list, getters and writes: what it
// requests, what it keeps on success, and the error it keeps when the server
// refuses or the network fails. Deleting has its own spec.
//
// @spec openspec/specs/process-configuration/spec.md

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useProcessTemplatesStore } from '../../src/store/modules/processTemplates.js'

describe('process templates store: list and getters', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useProcessTemplatesStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	it('fetchTemplates GETs the list and keeps its results', async () => {
		const results = [{ id: 'a', builtIn: true }, { id: 'b' }, { id: 'c', builtIn: false }]
		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ results }) })
		store.error = 'stale'

		await expect(store.fetchTemplates()).resolves.toEqual(results)

		const [url, options] = globalThis.fetch.mock.calls[0]
		expect(url).toContain('/apps/decidiq/api/process-templates')
		expect(options.method).toBeUndefined()
		expect(options.headers).toHaveProperty('requesttoken')
		expect(store.templates).toEqual(results)
		expect(store.error).toBe('')
		expect(store.loading).toBe(false)
		expect(store.builtInTemplates.map((t) => t.id)).toEqual(['a'])
		expect(store.customTemplates.map((t) => t.id)).toEqual(['b', 'c'])
	})

	it('fetchTemplates keeps an empty list when the body has no results array', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ results: 'x' }) })
		store.templates = [{ id: 'old' }]

		await expect(store.fetchTemplates()).resolves.toEqual([])
		expect(store.templates).toEqual([])
	})

	it('fetchTemplates keeps the status on a refusal and leaves the list alone', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok: false, status: 403 })
		store.templates = [{ id: 'old' }]

		await expect(store.fetchTemplates()).resolves.toEqual([])
		expect(store.error).toBe('Failed to load templates (403)')
		expect(store.templates).toEqual([{ id: 'old' }])
		expect(store.loading).toBe(false)
	})

	it('fetchTemplates keeps the failure message when the request throws', async () => {
		globalThis.fetch = vi.fn().mockRejectedValueOnce(new Error('offline'))
		await expect(store.fetchTemplates()).resolves.toEqual([])
		expect(store.error).toBe('offline')
		expect(store.loading).toBe(false)

		globalThis.fetch = vi.fn().mockRejectedValueOnce('down')
		await store.fetchTemplates()
		expect(store.error).toBe('down')
	})
})

describe('process templates store: writes', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useProcessTemplatesStore()
		store.fetchTemplates = vi.fn().mockResolvedValue([])
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	/**
	 * A fetch answering once with this status and body.
	 *
	 * @param {boolean} ok Whether the response is ok.
	 * @param {number} status The status.
	 * @param {Function} json The body reader.
	 * @return {Function} The mock.
	 */
	function answer(ok, status, json) {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok, status, json })
		return globalThis.fetch
	}

	it('createTemplate POSTs the template as JSON and refreshes', async () => {
		const fetchMock = answer(true, 201, async () => ({ id: 'new' }))
		store.error = 'stale'

		await expect(store.createTemplate({ name: 'Raad' })).resolves.toEqual({ id: 'new' })

		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toMatch(/\/apps\/decidiq\/api\/process-templates$/)
		expect(options.method).toBe('POST')
		expect(options.headers['Content-Type']).toBe('application/json')
		expect(options.headers).toHaveProperty('requesttoken')
		expect(JSON.parse(options.body)).toEqual({ name: 'Raad' })
		expect(store.fetchTemplates).toHaveBeenCalledTimes(1)
		expect(store.error).toBe('')
	})

	it('updateTemplate PUTs to the encoded id', async () => {
		const fetchMock = answer(true, 200, async () => ({ id: 'a/b' }))

		await expect(store.updateTemplate('a/b', { name: 'X' })).resolves.toEqual({ id: 'a/b' })

		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toMatch(/\/apps\/decidiq\/api\/process-templates\/a%2Fb$/)
		expect(options.method).toBe('PUT')
		expect(JSON.parse(options.body)).toEqual({ name: 'X' })
	})

	it('duplicateTemplate POSTs the new name to the duplicate route', async () => {
		const fetchMock = answer(true, 201, async () => ({ id: 'copy' }))

		await expect(store.duplicateTemplate('t 1', 'Kopie')).resolves.toEqual({ id: 'copy' })

		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toMatch(/\/apps\/decidiq\/api\/process-templates\/t%201\/duplicate$/)
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({ name: 'Kopie' })
	})

	it('a refused write keeps the server message, or the status, and does not refresh', async () => {
		answer(false, 422, async () => ({ message: 'Name taken' }))
		await expect(store.createTemplate({})).resolves.toBeNull()
		expect(store.error).toBe('Name taken')

		answer(false, 500, async () => {
			throw new Error('not json')
		})
		await expect(store.updateTemplate('t1', {})).resolves.toBeNull()
		expect(store.error).toBe('Request failed (500)')
		expect(store.fetchTemplates).not.toHaveBeenCalled()
	})

	it('a successful write with an unreadable body answers an empty object', async () => {
		answer(true, 204, async () => {
			throw new Error('no body')
		})
		await expect(store.createTemplate({})).resolves.toEqual({})
		expect(store.fetchTemplates).toHaveBeenCalledTimes(1)
	})

	it('a write that throws keeps the failure message', async () => {
		globalThis.fetch = vi.fn().mockRejectedValueOnce(new Error('offline'))
		await expect(store.duplicateTemplate('t1', 'n')).resolves.toBeNull()
		expect(store.error).toBe('offline')
	})
})
