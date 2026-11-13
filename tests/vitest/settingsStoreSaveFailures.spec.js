// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Saving settings when the server refuses or the network fails: the store
// answers null, keeps the settings it had and clears loading.
//
// @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-10

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

describe('settings store: saveSettings failures', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSettingsStore()
		store.settings = { register: 'r1' }
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	it('answers null and keeps the settings on a refusal', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({ ok: false, status: 403, json: async () => ({ config: {} }) })

		await expect(store.saveSettings({ register: 'r2' })).resolves.toBeNull()
		expect(store.settings).toEqual({ register: 'r1' })
		expect(store.loading).toBe(false)
	})

	it('answers null, logs and keeps the settings when the request throws', async () => {
		const error = new Error('offline')
		globalThis.fetch = vi.fn().mockRejectedValueOnce(error)
		const log = vi.spyOn(console, 'error').mockImplementation(() => {})

		await expect(store.saveSettings({ register: 'r2' })).resolves.toBeNull()
		expect(log).toHaveBeenCalledWith('Failed to save settings:', error)
		expect(store.settings).toEqual({ register: 'r1' })
		expect(store.loading).toBe(false)
	})
})
