<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAdminSettingsShell
		app-id="decidesk"
		app-name="Decidesk"
		data-testid="admin-root"
		@reimported="onReimported">
		<Settings v-if="storesReady" />

		<PublicationSettings v-if="storesReady" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import PublicationSettings from './PublicationSettings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		Settings,
		PublicationSettings,
	},
	data() {
		return {
			storesReady: false,
		}
	},
	/** @spec exclude lifecycle hook; only boots Pinia stores then flips the storesReady flag, framework setup */
	async created() {
		await initializeStores()
		this.storesReady = true
	},
	methods: {
		/** @spec exclude re-init stores after a configuration re-import; no business logic */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
