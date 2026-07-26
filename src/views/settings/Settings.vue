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
					variant="primary"
					native-type="submit"
					:disabled="saving">
					{{ saving ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- Organization defaults -->
		<!-- @spec openspec/specs/admin-settings/spec.md -->
		<CnSettingsSection
			:name="t('decidesk', 'Organization')"
			:description="t('decidesk', 'Organization defaults applied to meetings, decisions, and generated documents')">
			<form data-testid="organisation-settings" @submit.prevent="saveOrganisation">
				<div class="form-group">
					<label for="organisation_name">{{ t('decidesk', 'Organization name') }}</label>
					<input
						id="organisation_name"
						v-model="form.organisation_name"
						type="text"
						data-testid="organisation-name"
						:placeholder="t('decidesk', 'e.g. Vereniging De Harmonie')">
				</div>
				<div class="form-group">
					<label for="organisation_logo">{{ t('decidesk', 'Logo URL') }}</label>
					<input
						id="organisation_logo"
						v-model="form.organisation_logo"
						type="url"
						data-testid="organisation-logo"
						:placeholder="t('decidesk', 'https://example.org/logo.png')">
				</div>
				<div class="form-group">
					<NcSelect
						v-model="form.organisation_timezone"
						:input-label="t('decidesk', 'Timezone')"
						:options="timezoneOptions"
						data-testid="organisation-timezone" />
				</div>
				<div class="form-group">
					<NcSelect
						v-model="form.organisation_locale"
						:input-label="t('decidesk', 'Default language')"
						:options="localeOptions"
						label="label"
						data-testid="organisation-locale" />
				</div>
				<div class="form-group">
					<NcSelect
						v-model="form.organisation_currency"
						:input-label="t('decidesk', 'Currency')"
						:options="currencyOptions"
						data-testid="organisation-currency" />
				</div>
				<div class="form-group">
					<label for="organisation_retention_days">{{ t('decidesk', 'Archival retention period (days)') }}</label>
					<input
						id="organisation_retention_days"
						v-model="form.organisation_retention_days"
						type="number"
						min="0"
						step="1"
						data-testid="organisation-retention"
						:placeholder="t('decidesk', 'e.g. 3650')">
				</div>

				<div v-if="organisationMessage" class="success-message" data-testid="organisation-saved">
					{{ organisationMessage }}
				</div>

				<NcButton
					variant="primary"
					native-type="submit"
					data-testid="organisation-save"
					:disabled="savingOrganisation">
					{{ savingOrganisation ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- ORI publication settings -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1 -->
		<CnSettingsSection
			:name="t('decidesk', 'ORI endpoint')"
			:description="t('decidesk', 'ORI API endpoint URL')">
			<form @submit.prevent="saveOri">
				<div class="form-group">
					<label for="ori_endpoint">{{ t('decidesk', 'ORI endpoint') }}</label>
					<input
						id="ori_endpoint"
						v-model="form.ori_endpoint"
						type="url"
						placeholder="https://api.ori.example.nl/v1/stemmingen">
				</div>

				<NcButton
					variant="primary"
					native-type="submit"
					:disabled="savingOri">
					{{ savingOri ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- Process template management -->
		<!-- @spec openspec/specs/process-configuration/spec.md -->
		<ProcessTemplates />

		<!-- Email voting toggle -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.2 -->
		<CnSettingsSection
			:name="t('decidesk', 'Email voting')"
			:description="t('decidesk', 'Enable voting by email reply')">
			<div class="form-group form-group--checkbox">
				<input
					id="email_voting_enabled"
					v-model="form.email_voting_enabled"
					type="checkbox"
					:aria-label="t('decidesk', 'Email voting')"
					@change="saveEmailVoting">
				<label for="email_voting_enabled">{{ t('decidesk', 'Enable voting by email reply') }}</label>
			</div>
		</CnSettingsSection>

		<!-- Citizen-participation instance defaults -->
		<!-- @spec openspec/specs/citizen-participation/spec.md -->
		<CnSettingsSection
			:name="t('decidesk', 'Citizen participation defaults')"
			:description="t('decidesk', 'Defaults applied to new consultations and budget rounds. Staff can override per round.')">
			<form data-testid="participation-settings" @submit.prevent="saveParticipation">
				<div class="form-group">
					<NcSelect
						v-model="form.participation_default_moderation_policy"
						:options="moderationPolicyOptions"
						:input-label="t('decidesk', 'Default moderation policy')"
						label="label"
						track-by="id"
						data-testid="participation-moderation-policy" />
				</div>
				<div class="form-group">
					<label for="participation_catalog">{{ t('decidesk', 'Default OpenCatalogi catalog (UUID)') }}</label>
					<input
						id="participation_catalog"
						v-model="form.participation_catalog"
						type="text"
						data-testid="participation-catalog"
						:placeholder="t('decidesk', 'Leave empty to skip catalog routing')">
				</div>
				<div class="form-group">
					<label for="participation_anon_rate_limit">{{ t('decidesk', 'Anonymous intake rate limit (per hour)') }}</label>
					<input
						id="participation_anon_rate_limit"
						v-model="form.participation_anon_rate_limit"
						type="number"
						min="1"
						data-testid="participation-rate-limit"
						placeholder="5">
				</div>
				<NcButton
					variant="primary"
					native-type="submit"
					data-testid="participation-save"
					:disabled="savingParticipation">
					{{ savingParticipation ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useSettingsStore } from '../../store/modules/settings.js'
import ProcessTemplates from '../../components/processTemplates/ProcessTemplates.vue'

export default {
	name: 'Settings',
	components: {
		NcButton,
		NcSelect,
		CnSettingsSection,
		ProcessTemplates,
	},
	data() {
		return {
			form: {
				register: '',
				ori_endpoint: '',
				email_voting_enabled: false,
				organisation_name: '',
				organisation_logo: '',
				organisation_timezone: '',
				organisation_locale: null,
				organisation_currency: '',
				organisation_retention_days: '',
				participation_default_moderation_policy: null,
				participation_catalog: '',
				participation_anon_rate_limit: '',
			},
			saving: false,
			savingOri: false,
			savingOrganisation: false,
			savingParticipation: false,
			successMessage: '',
			organisationMessage: '',
		}
	},
	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		timezoneOptions() {
			if (typeof Intl.supportedValuesOf === 'function') {
				return Intl.supportedValuesOf('timeZone')
			}
			return ['Europe/Amsterdam', 'Europe/Brussels', 'Europe/Berlin', 'Europe/Paris', 'Europe/London', 'UTC']
		},
		/** @spec openspec/specs/admin-settings/spec.md */
		localeOptions() {
			return [
				{ id: 'nl', label: this.t('decidesk', 'Dutch') },
				{ id: 'en', label: this.t('decidesk', 'English') },
			]
		},
		/** @spec openspec/specs/admin-settings/spec.md */
		currencyOptions() {
			return ['EUR', 'USD', 'GBP', 'CHF']
		},
		/** @spec openspec/specs/citizen-participation/spec.md */
		moderationPolicyOptions() {
			return [
				{ id: 'pre-moderation', label: this.t('decidesk', 'Pre-moderation (approve before counting)') },
				{ id: 'post-moderation', label: this.t('decidesk', 'Post-moderation (auto-approve authenticated)') },
			]
		},
	},
	/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10 */
	created() {
		const settingsStore = useSettingsStore()
		const settings = settingsStore.settings || {}
		this.form.register = settings.register || ''
		this.form.ori_endpoint = settings.ori_endpoint || ''
		this.form.email_voting_enabled = settings.email_voting_enabled === '1' || settings.email_voting_enabled === true
		this.form.organisation_name = settings.organisation_name || ''
		this.form.organisation_logo = settings.organisation_logo || ''
		this.form.organisation_timezone = settings.organisation_timezone || ''
		this.form.organisation_locale = this.localeOptions.find((o) => o.id === settings.organisation_locale) || null
		this.form.organisation_currency = settings.organisation_currency || ''
		this.form.organisation_retention_days = settings.organisation_retention_days || ''
		this.form.participation_default_moderation_policy = this.moderationPolicyOptions.find((o) => o.id === settings.participation_default_moderation_policy) || this.moderationPolicyOptions[0]
		this.form.participation_catalog = settings.participation_catalog || ''
		this.form.participation_anon_rate_limit = settings.participation_anon_rate_limit || ''
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
		/** @spec openspec/specs/admin-settings/spec.md */
		async saveOrganisation() {
			this.savingOrganisation = true
			this.organisationMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings({
				organisation_name: this.form.organisation_name,
				organisation_logo: this.form.organisation_logo,
				organisation_timezone: this.form.organisation_timezone || '',
				organisation_locale: this.form.organisation_locale?.id || '',
				organisation_currency: this.form.organisation_currency || '',
				organisation_retention_days: String(this.form.organisation_retention_days || ''),
			})
			if (result) {
				this.organisationMessage = this.t('decidesk', 'Organization settings saved')
			}
			this.savingOrganisation = false
		},
		/** @spec openspec/specs/citizen-participation/spec.md */
		async saveParticipation() {
			this.savingParticipation = true
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings({
				participation_default_moderation_policy: this.form.participation_default_moderation_policy?.id || 'pre-moderation',
				participation_catalog: this.form.participation_catalog || '',
				participation_anon_rate_limit: String(this.form.participation_anon_rate_limit || ''),
			})
			this.savingParticipation = false
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
