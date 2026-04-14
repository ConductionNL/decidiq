// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for Minutes objects.
 *
 * Provides CRUD operations, lifecycle management, and draft generation
 * for Minutes via the OpenRegister ObjectService API.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
 */
export const useMinutesStore = defineStore('minutes', {
	state: () => ({
		minutes: [],
		currentMinutes: null,
		loading: false,
		total: 0,
		page: 1,
		limit: 20,
	}),

	getters: {
		getMinutes: (state) => state.minutes,
		getCurrentMinutes: (state) => state.currentMinutes,
		isLoading: (state) => state.loading,
	},

	actions: {
		/**
		 * Fetch a paginated list of Minutes objects.
		 *
		 * @param params
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async fetchMinutes(params = {}) {
			this.loading = true
			try {
				const url = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				url.searchParams.set('register', 'decidesk')
				url.searchParams.set('schema', 'minutes')
				url.searchParams.set('_page', params.page ?? this.page)
				url.searchParams.set('_limit', params.limit ?? this.limit)
				if (params.search) url.searchParams.set('_search', params.search)
				if (params.lifecycle) url.searchParams.set('lifecycle', params.lifecycle)

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.minutes = data.results ?? data
					this.total = data.total ?? this.minutes.length
					return this.minutes
				}
			} catch (error) {
				console.error('Failed to fetch Minutes:', error)
			} finally {
				this.loading = false
			}
			return []
		},

		/**
		 * Fetch a single Minutes object by ID.
		 *
		 * @param id
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async fetchMinutesById(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${id}?register=decidesk&schema=minutes`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentMinutes = await response.json()
					return this.currentMinutes
				}
			} catch (error) {
				console.error('Failed to fetch Minutes by ID:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Save (create or update) a Minutes object.
		 *
		 * @param minutesData
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async saveMinutes(minutesData) {
			this.loading = true
			try {
				const isNew = !minutesData.id
				const url = isNew
					? generateUrl('/apps/openregister/api/objects?register=decidesk&schema=minutes')
					: generateUrl(`/apps/openregister/api/objects/${minutesData.id}?register=decidesk&schema=minutes`)

				const response = await fetch(url, {
					method: isNew ? 'POST' : 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(minutesData),
				})
				if (response.ok) {
					const saved = await response.json()
					this.currentMinutes = saved
					await this.fetchMinutes()
					return saved
				}
			} catch (error) {
				console.error('Failed to save Minutes:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Delete a Minutes object.
		 *
		 * @param id
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async deleteMinutes(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${id}?register=decidesk&schema=minutes`)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					await this.fetchMinutes()
					return true
				}
			} catch (error) {
				console.error('Failed to delete Minutes:', error)
			} finally {
				this.loading = false
			}
			return false
		},

		/**
		 * Call the generate-draft API endpoint and return the preview text.
		 *
		 * @param {string} minutesId - UUID of the Minutes object
		 * @return {Promise<string|null>} Generated draft text or null on failure
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async generateDraft(minutesId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${minutesId}/generate-draft`)
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					return data.preview ?? null
				}
			} catch (error) {
				console.error('Failed to generate draft:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
