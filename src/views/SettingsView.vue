<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.1
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.2
 @spec openspec/changes/p1-crud-operations/tasks.md#task-10.1
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
					<p>{{ t('decidesk', 'For support, contact us at {email}', { email: 'support@conduction.nl' }) }}</p>
				</div>
			</template>
		</CnVersionInfoCard>

		<CnRegisterMapping
			v-if="isAdmin"
			:name="t('decidesk', 'Register Configuration')"
			:description="t('decidesk', 'Configure the OpenRegister schema mappings for all Decidesk object types.')"
			:groups="registerGroups"
			:configuration="registerConfiguration"
			:show-reimport-button="false"
			:reimporting="reimporting"
			@save="handleMappingSave"
			@reimport="reimportRegister" />

		<CnSettingsSection
			v-if="isAdmin"
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
import { CnVersionInfoCard, CnRegisterMapping, CnSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { useSettingsStore } from '../store/modules/settings.js'

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
		CnRegisterMapping,
		CnSettingsSection,
	},

	data() {
		return {
			reimporting: false,
			appVersion: document.getElementById('content')?.dataset?.version || '0.1.0',
			registerGroups: [
				{
					name: this.t('decidesk', 'Decidesk'),
					types: [
						{ slug: 'governanceBody', label: this.t('decidesk', 'Governance Bodies') },
						{ slug: 'meeting', label: this.t('decidesk', 'Meetings') },
						{ slug: 'participant', label: this.t('decidesk', 'Participants') },
						{ slug: 'agendaItem', label: this.t('decidesk', 'Agenda Items') },
						{ slug: 'motion', label: this.t('decidesk', 'Motions') },
						{ slug: 'amendment', label: this.t('decidesk', 'Amendments') },
						{ slug: 'votingRound', label: this.t('decidesk', 'Voting Rounds') },
						{ slug: 'vote', label: this.t('decidesk', 'Votes') },
						{ slug: 'decision', label: this.t('decidesk', 'Decisions') },
						{ slug: 'actionItem', label: this.t('decidesk', 'Action Items') },
						{ slug: 'minutes', label: this.t('decidesk', 'Minutes') },
						{ slug: 'digitalDocument', label: this.t('decidesk', 'Digital Documents') },
						{ slug: 'monetaryAmount', label: this.t('decidesk', 'Monetary Amounts') },
						{ slug: 'offer', label: this.t('decidesk', 'Offers') },
						{ slug: 'order', label: this.t('decidesk', 'Orders') },
						{ slug: 'product', label: this.t('decidesk', 'Products') },
						{ slug: 'report', label: this.t('decidesk', 'Reports') },
					],
				},
			],
		}
	},

	computed: {
		isAdmin() {
			return useSettingsStore().getIsAdmin
		},

		registerConfiguration() {
			return useSettingsStore().getSettings
		},
	},

	methods: {
		/**
		 * Save register mapping configuration.
		 *
		 * @param {object} config Updated configuration from CnRegisterMapping.
		 */
		async handleMappingSave(config) {
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings(config)
		},

		/**
		 * Re-import the register configuration.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.2
		 */
		async reimportRegister() {
			this.reimporting = true
			try {
				const { data } = await axios.post(generateUrl('/apps/decidesk/api/settings/load'))
				if (data.success) {
					showSuccess(this.t('decidesk', 'Register successfully reimported.'))
				} else {
					showError(data.message || this.t('decidesk', 'Failed to reimport register.'))
				}
			} catch (error) {
				console.error('Register reimport failed:', error.message)
				showError(this.t('decidesk', 'Failed to reimport register.'))
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
