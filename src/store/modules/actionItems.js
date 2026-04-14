// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for ActionItem objects.
 *
 * Provides CRUD operations and status transitions for ActionItems
 * via the OpenRegister ObjectService API.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
 */
export const useActionItemStore = defineStore('actionItem', {
	state: () => ({
		actionItems: [],
		currentActionItem: null,
		loading: false,
		total: 0,
		page: 1,
		limit: 20,
	}),

	getters: {
		getActionItems: (state) => state.actionItems,
		getCurrentActionItem: (state) => state.currentActionItem,
		isLoading: (state) => state.loading,

		/**
		 * Compute overdue items client-side for immediate visual feedback.
		 * The background job persists the overdue status for filtering/reporting.
		 *
		 * @param state
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
		 */
		overdueItems: (state) => {
			const today = new Date()
			return state.actionItems.filter((item) => {
				if (!item.dueDate || item.taskStatus === 'completed') return false
				return new Date(item.dueDate) < today && item.taskStatus !== 'overdue'
			})
		},
	},

	actions: {
		/**
		 * Fetch a paginated list of ActionItem objects.
		 *
		 * @param params
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async fetchActionItems(params = {}) {
			this.loading = true
			try {
				const url = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				url.searchParams.set('register', 'decidesk')
				url.searchParams.set('schema', 'action-item')
				url.searchParams.set('_page', params.page ?? this.page)
				url.searchParams.set('_limit', params.limit ?? this.limit)
				if (params.search) url.searchParams.set('_search', params.search)
				if (params.taskStatus) url.searchParams.set('taskStatus', params.taskStatus)
				if (params.assignee) url.searchParams.set('assignee', params.assignee)

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.actionItems = data.results ?? data
					this.total = data.total ?? this.actionItems.length
					return this.actionItems
				}
			} catch (error) {
				console.error('Failed to fetch ActionItems:', error)
			} finally {
				this.loading = false
			}
			return []
		},

		/**
		 * Fetch a single ActionItem object by ID.
		 *
		 * @param id
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async fetchActionItemById(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${id}?register=decidesk&schema=action-item`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentActionItem = await response.json()
					return this.currentActionItem
				}
			} catch (error) {
				console.error('Failed to fetch ActionItem by ID:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Save (create or update) an ActionItem object.
		 *
		 * @param actionItemData
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async saveActionItem(actionItemData) {
			this.loading = true
			try {
				const isNew = !actionItemData.id
				const url = isNew
					? generateUrl('/apps/openregister/api/objects?register=decidesk&schema=action-item')
					: generateUrl(`/apps/openregister/api/objects/${actionItemData.id}?register=decidesk&schema=action-item`)

				const response = await fetch(url, {
					method: isNew ? 'POST' : 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(actionItemData),
				})
				if (response.ok) {
					const saved = await response.json()
					this.currentActionItem = saved
					await this.fetchActionItems()
					return saved
				}
			} catch (error) {
				console.error('Failed to save ActionItem:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Delete an ActionItem object.
		 *
		 * @param id
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		 */
		async deleteActionItem(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${id}?register=decidesk&schema=action-item`)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					await this.fetchActionItems()
					return true
				}
			} catch (error) {
				console.error('Failed to delete ActionItem:', error)
			} finally {
				this.loading = false
			}
			return false
		},

		/**
		 * Transition an ActionItem status to 'in-progress'.
		 *
		 * @param {string} id - UUID of the ActionItem
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
		 */
		async startActionItem(id) {
			await this.fetchActionItemById(id)
			if (!this.currentActionItem) return null
			return this.saveActionItem({ ...this.currentActionItem, taskStatus: 'in-progress' })
		},

		/**
		 * Transition an ActionItem status to 'completed' and record completedAt.
		 *
		 * @param {string} id - UUID of the ActionItem
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
		 */
		async completeActionItem(id) {
			await this.fetchActionItemById(id)
			if (!this.currentActionItem) return null
			return this.saveActionItem({
				...this.currentActionItem,
				taskStatus: 'completed',
				completedAt: new Date().toISOString(),
			})
		},
	},
})
