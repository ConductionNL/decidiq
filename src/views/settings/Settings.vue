<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
-->
<template>
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

			<!-- ORI endpoint (task-10) -->
			<div class="form-group">
				<label for="ori-endpoint">{{ t('decidesk', 'ORI-eindpunt') }}</label>
				<input
					id="ori-endpoint"
					v-model="form.ori_endpoint"
					type="url"
					:placeholder="t('decidesk', 'ORI Endpoint URL')"
					:aria-label="t('decidesk', 'ORI Endpoint URL')">
			</div>

			<!-- Email voting toggle (task-10) -->
			<div class="form-group form-group--checkbox">
				<label>
					<input
						v-model="form.email_voting_enabled"
						type="checkbox"
						:aria-label="t('decidesk', 'Enable email voting')">
					{{ t('decidesk', 'E-mail stemmen') }}
				</label>
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
			successMessage: '',
		}
	},
	created() {
		const settingsStore = useSettingsStore()
		this.form.register = settingsStore.settings?.register || ''
		this.form.ori_endpoint = settingsStore.settings?.ori_endpoint || ''
		this.form.email_voting_enabled = settingsStore.settings?.email_voting_enabled === 'true'
	},
	methods: {
		async save() {
			this.saving = true
			this.successMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings(this.form)
			if (result) {
				this.successMessage = t('decidesk', 'Settings saved successfully')
			}
			this.saving = false
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
.success-message {
	color: var(--color-success);
	margin-bottom: 8px;
}
</style>
