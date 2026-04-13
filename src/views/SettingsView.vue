<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.1
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.2
-->
<template>
	<div class="decidesk-settings">
		<h1>{{ t('decidesk', 'Instellingen') }}</h1>

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

		<CnSettingsSection
			:name="t('decidesk', 'Register')"
			:description="t('decidesk', 'Manage the OpenRegister configuration for Decidesk.')">
			<NcButton
				type="secondary"
				:disabled="reimporting"
				@click="reimportRegister">
				{{ reimporting ? t('decidesk', 'Importing...') : t('decidesk', 'Register opnieuw importeren') }}
			</NcButton>
		</CnSettingsSection>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnVersionInfoCard, CnSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

/**
 * Settings page with version info, register mapping, and re-import button.
 *
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.1
 */
export default {
	name: 'SettingsView',
	components: {
		NcButton,
		CnVersionInfoCard,
		CnSettingsSection,
	},

	data() {
		return {
			reimporting: false,
			appVersion: document.getElementById('content')?.dataset?.version || '0.1.0',
		}
	},

	methods: {
		/**
		 * Re-import the register configuration.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.2
		 */
		async reimportRegister() {
			this.reimporting = true
			try {
				const response = await fetch(generateUrl('/apps/decidesk/api/settings/load'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				const data = await response.json()
				if (data.success) {
					showSuccess(t('decidesk', 'Register successfully reimported.'))
				} else {
					showError(data.message || t('decidesk', 'Failed to reimport register.'))
				}
			} catch (error) {
				console.error('Register reimport failed:', error)
				showError(t('decidesk', 'Failed to reimport register.'))
			} finally {
				this.reimporting = false
			}
		},
	},
}
</script>

<style scoped>
.decidesk-settings {
	max-width: 900px;
	padding: 0 4px;
}

.decidesk-settings h1 {
	margin: 0 0 20px;
	font-size: 22px;
	font-weight: 600;
}
</style>
