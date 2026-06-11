<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings — includes ORI endpoint and email voting toggle.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
-->
<template>
	<div>
		<CnSettingsSection
			:name="t('decidesk', 'Configuration')"
			:description="t('decidesk', 'Configure the app settings')">
			<form @submit.prevent="save">
				<div class="form-group">
					<label for="register">{{ t('decidesk', 'Register') }}</label>
					<input
						id="register"
						v-model="form.register"
						type="text"
						:placeholder="t('decidesk', 'OpenRegister register ID')">
				</div>

				<div v-if="successMessage" class="success-message">
					{{ successMessage }}
				</div>

				<NcButton
					type="primary"
					native-type="submit"
					:disabled="saving">
					{{ saving ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- ORI publication settings -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1 -->
		<CnSettingsSection
			:name="t('decidesk', 'ORI-eindpunt')"
			:description="t('decidesk', 'ORI API endpoint URL')">
			<form @submit.prevent="saveOri">
				<div class="form-group">
					<label for="ori_endpoint">{{ t('decidesk', 'ORI-eindpunt') }}</label>
					<input
						id="ori_endpoint"
						v-model="form.ori_endpoint"
						type="url"
						placeholder="https://api.ori.example.nl/v1/stemmingen">
				</div>

				<NcButton
					type="primary"
					native-type="submit"
					:disabled="savingOri">
					{{ savingOri ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- Email voting toggle -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.2 -->
		<CnSettingsSection
			:name="t('decidesk', 'E-mail stemmen')"
			:description="t('decidesk', 'Enable voting by email reply')">
			<div class="form-group form-group--checkbox">
				<input
					id="email_voting_enabled"
					v-model="form.email_voting_enabled"
					type="checkbox"
					:aria-label="t('decidesk', 'E-mail stemmen')"
					@change="saveEmailVoting">
				<label for="email_voting_enabled">{{ t('decidesk', 'Enable voting by email reply') }}</label>
			</div>
		</CnSettingsSection>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		CnSettingsSection,
	},
	data() {
		return {
			form: {
				register: '',
				ori_endpoint: '',
				email_voting_enabled: false,
			},
			saving: false,
			savingOri: false,
			successMessage: '',
		}
	},
	/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10 */
	created() {
		const settingsStore = useSettingsStore()
		this.form.register = settingsStore.settings?.register || ''
		this.form.ori_endpoint = settingsStore.settings?.ori_endpoint || ''
		this.form.email_voting_enabled = settingsStore.settings?.email_voting_enabled === '1' || settingsStore.settings?.email_voting_enabled === true
	},
	methods: {
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10 */
		async save() {
			this.saving = true
			this.successMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings({ register: this.form.register })
			if (result) {
				this.successMessage = this.t('decidesk', 'Settings saved successfully')
			}
			this.saving = false
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1 */
		async saveOri() {
			this.savingOri = true
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings({ ori_endpoint: this.form.ori_endpoint })
			this.savingOri = false
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.2 */
		async saveEmailVoting() {
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings({
				email_voting_enabled: this.form.email_voting_enabled ? '1' : '0',
			})
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.form-group--checkbox {
	display: flex;
	align-items: center;
	gap: 8px;
}

.form-group--checkbox label {
	margin-bottom: 0;
}

.success-message {
	color: var(--color-success);
	margin-bottom: 8px;
}
</style>
