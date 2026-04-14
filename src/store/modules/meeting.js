// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-4
 */

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

/**
 * Pinia store for meeting lifecycle management.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-4
 */
export const useMeetingStore = defineStore('meeting', {
	state: () => ({
		loading: false,
		currentUserRole: 'none',
	}),

	actions: {
		/**
		 * Transition a meeting to a new lifecycle state.
		 *
		 * @param {string} meetingId The meeting ID
		 * @param {string} transition The transition name
		 * @return {Promise<object>} The transition result
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-4
		 */
		async transitionLifecycle(meetingId, transition) {
			this.loading = true
			try {
				const url = generateUrl('/apps/decidesk/api/meetings/{id}/lifecycle', { id: meetingId })
				const response = await fetch(url, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify({ transition }),
				})
				const data = await response.json()
				if (!response.ok) {
					throw new Error(data.error || 'Transition failed')
				}
				return data
			} catch (error) {
				console.error('Failed to transition meeting lifecycle:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the current user's role for a meeting.
		 *
		 * @param {string} meetingId The meeting ID
		 * @return {Promise<string>} The user's role
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-4
		 */
		async fetchUserRole(meetingId) {
			try {
				const url = generateUrl('/apps/decidesk/api/meetings/{id}/user-role', { id: meetingId })
				const response = await fetch(url, {
					headers: { requesttoken: getRequestToken() },
				})
				const data = await response.json()
				this.currentUserRole = data.role || 'none'
				return this.currentUserRole
			} catch (error) {
				console.error('Failed to fetch user role:', error)
				this.currentUserRole = 'none'
				return 'none'
			}
		},
	},
})
