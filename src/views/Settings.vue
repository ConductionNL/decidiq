<template>
	<div class="decidesk-settings">
		<CnVersionInfoCard
			:app-name="'Decidesk'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="false"
			:title="t('decidesk', 'Version Information')"
			:description="t('decidesk', 'Information about the current Decidesk installation')" />

		<CnRegisterMapping
			v-if="isAdmin"
			:register-slug="'decidesk'" />

		<div v-if="isAdmin" class="decidesk-settings__reimport">
			<h3>{{ t('decidesk', 'Register Configuration') }}</h3>
			<p>{{ t('decidesk', 'Re-import the register configuration from the app\'s JSON definition.') }}</p>
			<NcButton
				type="primary"
				:disabled="reimporting"
				@click="reimport">
				{{ reimporting ? t('decidesk', 'Importing...') : t('decidesk', 'Re-import Register') }}
			</NcButton>
			<p v-if="reimportMessage" :class="reimportSuccess ? 'success-message' : 'error-message'">
				{{ reimportMessage }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnVersionInfoCard, CnRegisterMapping } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { useSettingsStore } from '../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		CnVersionInfoCard,
		CnRegisterMapping,
	},
	data() {
		return {
			appVersion: '0.1.0',
			reimporting: false,
			reimportMessage: '',
			reimportSuccess: false,
		}
	},
	computed: {
		isAdmin() {
			const settingsStore = useSettingsStore()
			return settingsStore.getIsAdmin
		},
	},
	methods: {
		async reimport() {
			this.reimporting = true
			this.reimportMessage = ''
			try {
				const response = await fetch(generateUrl('/apps/decidesk/api/settings/load'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				const data = await response.json()
				this.reimportSuccess = data.success
				this.reimportMessage = data.message
			} catch (error) {
				this.reimportSuccess = false
				this.reimportMessage = t('decidesk', 'Failed to re-import register configuration')
			} finally {
				this.reimporting = false
			}
		},
	},
}
</script>

<style scoped>
.decidesk-settings {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.decidesk-settings__reimport {
	margin-top: 24px;
}

.decidesk-settings__reimport h3 {
	margin: 0 0 8px;
	font-size: 18px;
	font-weight: 600;
}

.decidesk-settings__reimport p {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.success-message {
	color: var(--color-success) !important;
}

.error-message {
	color: var(--color-error) !important;
}
</style>
