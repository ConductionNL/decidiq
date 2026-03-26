<template>
	<div class="decidesk-admin">
		<CnVersionInfoCard
			:app-name="'Decidesk'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('decidesk', 'Version Information')"
			:description="t('decidesk', 'Information about the current Decidesk installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('decidesk', 'Support') }}</h4>
					<p>{{ t('decidesk', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
				</div>
			</template>
		</CnVersionInfoCard>

		<Settings v-if="storesReady" />
	</div>
</template>

<script>
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		Settings,
	},
	data() {
		return {
			storesReady: false,
			appVersion: document.getElementById('decidesk-settings')?.dataset?.version || 'Unknown',
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.decidesk-admin {
	max-width: 900px;
}
</style>
