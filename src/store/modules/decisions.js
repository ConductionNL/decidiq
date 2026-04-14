// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for Decision objects.
 *
 * Provides CRUD operations and ORI publication flagging for Decisions
 * via the OpenRegister ObjectService API.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
 */
export const useDecisionStore = defineStore('decision', {
	state: () => ({
		decisions: [],
		currentDecision: null,
		loading: false,
		total: 0,
		page: 1,
		limit: 20,
	}),

	getters: {
		getDecisions: (state) => state.decisions,
		getCurrentDecision: (state) => state.currentDecision,
		isLoading: (state) => state.loading,
	},

	actions: {
		/**
		 * Fetch a paginated list of Decision objects.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async fetchDecisions(params = {}) {
			this.loading = true
			try {
				const url = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				url.searchParams.set('register', 'decidesk')
				url.searchParams.set('schema', 'decision')
				url.searchParams.set('_page', params.page ?? this.page)
				url.searchParams.set('_limit', params.limit ?? this.limit)
				if (params.search) url.searchParams.set('_search', params.search)
				if (params.outcome) url.searchParams.set('outcome', params.outcome)
				if (params.isPublished !== undefined) url.searchParams.set('isPublished', params.isPublished)

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.decisions = data.results ?? data
					this.total = data.total ?? this.decisions.length
					return this.decisions
				}
			} catch (error) {
				console.error('Failed to fetch Decisions:', error)
			} finally {
				this.loading = false
			}
			return []
		},

		/**
		 * Fetch a single Decision object by ID.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async fetchDecisionById(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${id}?register=decidesk&schema=decision`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentDecision = await response.json()
					return this.currentDecision
				}
			} catch (error) {
				console.error('Failed to fetch Decision by ID:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Save (create or update) a Decision object.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async saveDecision(decisionData) {
			this.loading = true
			try {
				const isNew = !decisionData.id
				const url = isNew
					? generateUrl('/apps/openregister/api/objects?register=decidesk&schema=decision')
					: generateUrl(`/apps/openregister/api/objects/${decisionData.id}?register=decidesk&schema=decision`)

				const response = await fetch(url, {
					method: isNew ? 'POST' : 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(decisionData),
				})
				if (response.ok) {
					const saved = await response.json()
					this.currentDecision = saved
					await this.fetchDecisions()
					return saved
				}
			} catch (error) {
				console.error('Failed to save Decision:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Delete a Decision object.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async deleteDecision(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${id}?register=decidesk&schema=decision`)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					await this.fetchDecisions()
					return true
				}
			} catch (error) {
				console.error('Failed to delete Decision:', error)
			} finally {
				this.loading = false
			}
			return false
		},

		/**
		 * Publish a Decision via the dedicated server-side publish endpoint.
		 *
		 * Calls POST /apps/decidesk/api/decisions/{id}/publish which enforces
		 * server-side admin check, outcome validation, and isPublished guard —
		 * preventing frontend-only bypass (OWASP A01 / ADR-005).
		 *
		 * @param {string} id - UUID of the Decision object to publish
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
		 */
		async publishDecision(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/decisions/${id}/publish`)
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const saved = await response.json()
					this.currentDecision = saved
					await this.fetchDecisions()
					return saved
				}
				// Surface server error to caller for user-visible feedback.
				const errorBody = await response.json().catch(() => ({}))
				throw new Error(errorBody.message || `Publish failed with status ${response.status}`)
			} catch (error) {
				console.error('Failed to publish Decision:', error)
				throw error
			} finally {
				this.loading = false
			}
		},
	},
})
