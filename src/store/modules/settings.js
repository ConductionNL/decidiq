import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		hasOpenRegisters: false,
		isAdmin: false,
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
	},

	actions: {
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10 */
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/decidiq/api/settings'),
					{
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					this.hasOpenRegisters = !!data?.openregisters
					this.isAdmin = !!data?.isAdmin
					return data
				}
			} catch (error) {
				console.error('Failed to fetch settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * @param settings
		 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
		 */
		async saveSettings(settings) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/decidiq/api/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: getRequestToken(),
						},
						body: JSON.stringify(settings),
					},
				)
				if (response.ok) {
					const data = await response.json()
					// settings#create wraps the settings in a {success, config}
					// envelope — unwrap so this.settings stays the flat map the
					// rest of the app (useRelationStore, Settings.vue) reads.
					const saved = data?.config ?? data
					this.settings = saved
					return saved
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
