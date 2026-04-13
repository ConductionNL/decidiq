// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Store for agenda management operations.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-2
 */
export const useAgendaStore = defineStore('agenda', {
	state: () => ({
		loading: false,
		activeItemId: null,
	}),

	actions: {
		/**
		 * Publish the agenda for a meeting.
		 *
		 * @param {string} meetingId The meeting ID
		 * @return {Promise<object>} The result
		 */
		async publishAgenda(meetingId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/agendas/${meetingId}/publish`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				if (!response.ok) {
					throw new Error(`Publish agenda failed: ${response.status} ${response.statusText}`)
				}
				return await response.json()
			} catch (error) {
				console.error('Failed to publish agenda:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Advance the BOB phase of an agenda item.
		 *
		 * @param {string} agendaItemId The agenda item ID
		 * @return {Promise<object>} The result with new phase
		 */
		async advanceBobPhase(agendaItemId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/agenda-items/${agendaItemId}/bob-phase`)
				const response = await fetch(url, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				if (!response.ok) {
					throw new Error(`Advance BOB phase failed: ${response.status} ${response.statusText}`)
				}
				return await response.json()
			} catch (error) {
				console.error('Failed to advance BOB phase:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Process hamerstukken for a meeting.
		 *
		 * @param {string} meetingId The meeting ID
		 * @return {Promise<object>} The result with count
		 */
		async processHamerstukken(meetingId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/agendas/${meetingId}/hamerstukken`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				if (!response.ok) {
					throw new Error(`Process hamerstukken failed: ${response.status} ${response.statusText}`)
				}
				return await response.json()
			} catch (error) {
				console.error('Failed to process hamerstukken:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reorder agenda items for a meeting.
		 *
		 * @param {string} meetingId The meeting ID
		 * @param {Array<string>} ids Ordered array of agenda item IDs
		 * @return {Promise<object>} The result
		 */
		async reorderItems(meetingId, ids) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/agendas/${meetingId}/reorder`)
				const response = await fetch(url, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids }),
				})
				if (!response.ok) {
					throw new Error(`Reorder items failed: ${response.status} ${response.statusText}`)
				}
				return await response.json()
			} catch (error) {
				console.error('Failed to reorder agenda items:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the current user's role for a meeting.
		 *
		 * @param {string} meetingId The meeting ID
		 * @return {Promise<string>} The role ('chair', 'voorzitter', 'secretary', 'secretaris', 'member', 'none')
		 */
		async fetchUserRole(meetingId) {
			try {
				const url = generateUrl(`/apps/decidesk/api/agendas/${meetingId}/user-role`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				const data = await response.json()
				return data.role || 'none'
			} catch (error) {
				console.error('Failed to fetch user role:', error)
				return 'none'
			}
		},

		/**
		 * Set the currently active agenda item in the live view.
		 *
		 * @param {string|null} itemId The item ID or null to deactivate
		 */
		setActiveItem(itemId) {
			this.activeItemId = itemId
		},
	},
})
