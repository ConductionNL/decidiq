<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.1
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-7.2
 @spec openspec/changes/p1-crud-operations/tasks.md#task-10.1
-->
<template>
	<div class="decidesk-settings">
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
				{{ reimporting ? t('decidesk', 'Importing...') : t('decidesk', 'Reimport Register') }}
			</NcButton>
		</CnSettingsSection>

		<!-- ORI endpoint setting (task-10.1) -->
		<CnSettingsSection
			v-if="isAdmin"
			:name="t('decidesk', 'ORI Endpoint')"
			:description="t('decidesk', 'ORI endpoint URL for publishing voting results')">
			<div class="decidesk-settings-field">
				<input
					v-model="oriEndpoint"
					type="url"
					class="decidesk-settings-input"
					:placeholder="'https://ori.example.nl/api/v1/votes'"
					:aria-label="t('decidesk', 'ORI endpoint URL')">
				<NcButton
					type="primary"
					:disabled="savingOri"
					@click="saveOriEndpoint">
					{{ t('decidesk', 'Save') }}
				</NcButton>
			</div>
		</CnSettingsSection>

		<!-- Email voting toggle (task-10.2) -->
		<CnSettingsSection
			v-if="isAdmin"
			:name="t('decidesk', 'Email Voting')"
			:description="t('decidesk', 'Enable email vote reply parsing')">
			<label class="decidesk-toggle-label">
				<input
					v-model="emailVotingEnabled"
					type="checkbox"
					:aria-label="t('decidesk', 'Enable email vote reply parsing')"
					@change="saveEmailVoting">
				{{ t('decidesk', 'Enable email vote reply parsing') }}
			</label>
		</CnSettingsSection>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnVersionInfoCard, CnRegisterMapping, CnSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { getRequestToken } from '@nextcloud/auth'
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
			savingOri: false,
			oriEndpoint: '',
			emailVotingEnabled: false,
			appVersion: document.getElementById('content')?.dataset?.version || '0.1.0',
			registerGroups: [
				{
					name: this.t('decidesk', 'Decidesk'),
					types: [
						{ slug: 'governance-body', label: this.t('decidesk', 'Governance Bodies') },
						{ slug: 'meeting', label: this.t('decidesk', 'Meetings') },
						{ slug: 'participant', label: this.t('decidesk', 'Participants') },
						{ slug: 'agenda-item', label: this.t('decidesk', 'Agenda Items') },
						{ slug: 'motion', label: this.t('decidesk', 'Motions') },
						{ slug: 'amendment', label: this.t('decidesk', 'Amendments') },
						{ slug: 'voting-round', label: this.t('decidesk', 'Voting Rounds') },
						{ slug: 'vote', label: this.t('decidesk', 'Votes') },
						{ slug: 'decision', label: this.t('decidesk', 'Decisions') },
						{ slug: 'action-item', label: this.t('decidesk', 'Action Items') },
						{ slug: 'minutes', label: this.t('decidesk', 'Minutes') },
						{ slug: 'digital-document', label: this.t('decidesk', 'Digital Documents') },
						{ slug: 'monetary-amount', label: this.t('decidesk', 'Monetary Amounts') },
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
		 * Save ORI endpoint configuration.
		 *
		 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1
		 */
		async saveOriEndpoint() {
			this.savingOri = true
			try {
				const settingsStore = useSettingsStore()
				await settingsStore.saveSettings({ ori_endpoint: this.oriEndpoint })
				showSuccess(this.t('decidesk', 'Settings saved successfully'))
			} catch (error) {
				showError(this.t('decidesk', 'Failed to save settings'))
			} finally {
				this.savingOri = false
			}
		},

		/**
		 * Toggle email voting setting.
		 *
		 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.2
		 */
		async saveEmailVoting() {
			try {
				const settingsStore = useSettingsStore()
				await settingsStore.saveSettings({ email_voting_enabled: this.emailVotingEnabled ? '1' : '0' })
			} catch (error) {
				showError(this.t('decidesk', 'Failed to save settings'))
			}
		},

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
					headers: { requesttoken: getRequestToken() },
				})
				const data = await response.json()
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
	max-width: 56.25rem;
	padding: 0 var(--default-grid-baseline);
}

.decidesk-settings-field {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: center;
}

.decidesk-settings-input {
	flex: 1;
	padding: var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.decidesk-toggle-label {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	cursor: pointer;
}
</style>
