// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.1

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for Meeting objects.
 *
 * Provides CRUD operations, filtering, search, and lifecycle management
 * for Meetings via the OpenRegister ObjectService API and Decidesk API.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.1
 */
export const useMeetingStore = defineStore('meetings', {
	state: () => ({
		meetings: [],
		currentMeeting: null,
		loading: false,
		total: 0,
		page: 1,
		limit: 20,
		filters: {
			governanceBody: null,
			lifecycle: null,
			dateFrom: null,
			dateTo: null,
		},
		searchQuery: '',
	}),

	getters: {
		getMeetings: (state) => state.meetings,
		getCurrentMeeting: (state) => state.currentMeeting,
		isLoading: (state) => state.loading,
		getTotal: (state) => state.total,
		getPage: (state) => state.page,
	},

	actions: {
		/**
		 * Fetch a paginated list of Meeting objects.
		 *
		 * @param {object} params Pagination and filter overrides
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.3
		 */
		async fetchMeetings(params = {}) {
			this.loading = true
			try {
				const url = new URL(generateUrl('/apps/decidesk/api/meetings'), window.location.origin)
				url.searchParams.set('_page', params.page ?? this.page)
				url.searchParams.set('_limit', params.limit ?? this.limit)

				if (this.filters.governanceBody) {
					url.searchParams.set('governanceBody', this.filters.governanceBody)
				}
				if (this.filters.lifecycle) {
					url.searchParams.set('lifecycle', this.filters.lifecycle)
				}
				if (this.searchQuery) {
					url.searchParams.set('_search', this.searchQuery)
				}

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.meetings = data.results ?? data
					this.total = data.total ?? this.meetings.length
					return this.meetings
				}
			} catch (error) {
				console.error('Failed to fetch meetings:', error)
			} finally {
				this.loading = false
			}
			return []
		},

		/**
		 * Fetch a single Meeting object by ID.
		 *
		 * @param {string} id Meeting UUID
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-5.1
		 */
		async fetchMeetingById(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/meetings/${id}`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentMeeting = await response.json()
					return this.currentMeeting
				}
			} catch (error) {
				console.error(`Failed to fetch meeting ${id}:`, error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Create a new Meeting object.
		 *
		 * @param {object} meetingData Meeting fields to persist
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
		 */
		async createMeeting(meetingData) {
			this.loading = true
			try {
				const url = generateUrl('/apps/decidesk/api/meetings')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(meetingData),
				})
				if (response.ok) {
					const created = await response.json()
					this.meetings.push(created)
					this.currentMeeting = created
					return created
				}
			} catch (error) {
				console.error('Failed to create meeting:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Update an existing Meeting object.
		 *
		 * @param {string} id Meeting UUID
		 * @param {object} meetingData Updated meeting fields
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
		 */
		async updateMeeting(id, meetingData) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/meetings/${id}`)
				const response = await fetch(url, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(meetingData),
				})
				if (response.ok) {
					const updated = await response.json()
					const index = this.meetings.findIndex(m => m.id === id)
					if (index !== -1) {
						this.meetings[index] = updated
					}
					this.currentMeeting = updated
					return updated
				}
			} catch (error) {
				console.error(`Failed to update meeting ${id}:`, error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Delete a Meeting object.
		 *
		 * @param {string} id Meeting UUID
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
		 */
		async deleteMeeting(id) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/meetings/${id}`)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.meetings = this.meetings.filter(m => m.id !== id)
					if (this.currentMeeting?.id === id) {
						this.currentMeeting = null
					}
					return true
				}
			} catch (error) {
				console.error(`Failed to delete meeting ${id}:`, error)
			} finally {
				this.loading = false
			}
			return false
		},

		/**
		 * Apply a lifecycle transition to a meeting.
		 *
		 * @param {string} id Meeting UUID
		 * @param {string} action Lifecycle action name (e.g. "schedule", "cancel")
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-5.6
		 */
		async transitionMeeting(id, action) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/meetings/${id}/lifecycle`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ action }),
				})
				if (response.ok) {
					const updated = await response.json()
					const index = this.meetings.findIndex(m => m.id === id)
					if (index !== -1) {
						this.meetings[index] = updated
					}
					this.currentMeeting = updated
					return updated
				}
			} catch (error) {
				console.error(`Failed to transition meeting ${id}:`, error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Update search filters.
		 *
		 * @param {object} filters Partial filter fields to merge into state
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.3
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},

		/**
		 * Set search query.
		 *
		 * @param {string} query Full-text search string
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.4
		 */
		setSearchQuery(query) {
			this.searchQuery = query
		},

		/**
		 * Reset all filters and search.
		 *
		 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.3
		 */
		resetFilters() {
			this.filters = {
				governanceBody: null,
				lifecycle: null,
				dateFrom: null,
				dateTo: null,
			}
			this.searchQuery = ''
		},
	},
})
