<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings — includes ORI endpoint and email voting toggle.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
-->
<template>
	<div>
		<CnSettingsSection
			:name="t('decidiq', 'Configuration')"
			:description="t('decidiq', 'Configure the app settings')">
			<form @submit.prevent="save">
				<div class="form-group">
					<label for="register">{{ t('decidiq', 'Register') }}</label>
					<input
						id="register"
						v-model="form.register"
						type="text"
						:placeholder="t('decidiq', 'OpenRegister register ID')" />
				</div>

				<div v-if="successMessage" class="success-message">
					{{ successMessage }}
				</div>

				<NcButton variant="primary" type="submit" :disabled="saving">
					{{ saving ? t('decidiq', 'Saving...') : t('decidiq', 'Save') }}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- Organization defaults -->
		<!-- @spec openspec/specs/admin-settings/spec.md -->
		<CnSettingsSection
			:name="t('decidiq', 'Organization')"
			:description="
				t(
					'decidiq',
					'Organization defaults applied to meetings, decisions, and generated documents',
				)
			">
			<form
				data-testid="organisation-settings"
				@submit.prevent="saveOrganisation">
				<div class="form-group">
					<label for="organisation_name">{{
						t('decidiq', 'Organization name')
					}}</label>
					<input
						id="organisation_name"
						v-model="form.organisation_name"
						type="text"
						data-testid="organisation-name"
						:placeholder="t('decidiq', 'e.g. Vereniging De Harmonie')" />
				</div>
				<div class="form-group">
					<label for="organisation_logo">{{
						t('decidiq', 'Logo URL')
					}}</label>
					<input
						id="organisation_logo"
						v-model="form.organisation_logo"
						type="url"
						data-testid="organisation-logo"
						:placeholder="
							t('decidiq', 'https://example.org/logo.png')
						" />
				</div>
				<div class="form-group">
					<NcSelect
						v-model="form.organisation_timezone"
						:inputLabel="t('decidiq', 'Timezone')"
						:options="timezoneOptions"
						data-testid="organisation-timezone" />
				</div>
				<div class="form-group">
					<NcSelect
						v-model="form.organisation_locale"
						:inputLabel="t('decidiq', 'Default language')"
						:options="localeOptions"
						label="label"
						data-testid="organisation-locale" />
				</div>
				<div class="form-group">
					<NcSelect
						v-model="form.organisation_currency"
						:inputLabel="t('decidiq', 'Currency')"
						:options="currencyOptions"
						data-testid="organisation-currency" />
				</div>
				<div class="form-group">
					<label for="organisation_retention_days">{{
						t('decidiq', 'Archival retention period (days)')
					}}</label>
					<input
						id="organisation_retention_days"
						v-model="form.organisation_retention_days"
						type="number"
						min="0"
						step="1"
						data-testid="organisation-retention"
						:placeholder="t('decidiq', 'e.g. 3650')" />
				</div>

				<div
					v-if="organisationMessage"
					class="success-message"
					data-testid="organisation-saved">
					{{ organisationMessage }}
				</div>

				<NcButton
					variant="primary"
					type="submit"
					data-testid="organisation-save"
					:disabled="savingOrganisation">
					{{
						savingOrganisation
							? t('decidiq', 'Saving...')
							: t('decidiq', 'Save')
					}}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- Organisation mode -->
		<!--
		 REHOMED from the in-app `type:"settings"` page deleted under ADR-079 D1.
		 That page's "Advanced" section held three keys; `ori_endpoint` and
		 `email_voting_enabled` already had a home here, `organisatie_modus` did
		 not — this section was the difference, and deleting the page without it
		 would have left the key writable only by occ.
		 @spec openspec/specs/admin-settings/spec.md
		-->
		<CnSettingsSection
			:name="t('decidiq', 'Organisation mode')"
			:description="
				t(
					'decidiq',
					'Controls mode-specific labels across the app — for example what a governance body is called. Default: government.',
				)
			">
			<form
				data-testid="organisation-mode-settings"
				@submit.prevent="saveOrganisationMode">
				<div class="form-group">
					<NcSelect
						v-model="form.organisatie_modus"
						:options="organisationModeOptions"
						:inputLabel="t('decidiq', 'Organisation mode')"
						label="label"
						trackBy="id"
						data-testid="organisation-mode" />
				</div>

				<NcButton
					variant="primary"
					type="submit"
					data-testid="organisation-mode-save"
					:disabled="savingOrganisationMode">
					{{
						savingOrganisationMode
							? t('decidiq', 'Saving...')
							: t('decidiq', 'Save')
					}}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- ORI publication settings -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1 -->
		<CnSettingsSection
			:name="t('decidiq', 'ORI endpoint')"
			:description="t('decidiq', 'ORI API endpoint URL')">
			<form @submit.prevent="saveOri">
				<div class="form-group">
					<label for="ori_endpoint">{{
						t('decidiq', 'ORI endpoint')
					}}</label>
					<input
						id="ori_endpoint"
						v-model="form.ori_endpoint"
						type="url"
						placeholder="https://api.ori.example.nl/v1/stemmingen" />
				</div>

				<NcButton variant="primary" type="submit" :disabled="savingOri">
					{{
						savingOri ? t('decidiq', 'Saving...') : t('decidiq', 'Save')
					}}
				</NcButton>
			</form>
		</CnSettingsSection>

		<!-- Process template management -->
		<!-- @spec openspec/specs/process-configuration/spec.md -->
		<ProcessTemplates />

		<!-- Email voting toggle -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.2 -->
		<CnSettingsSection
			:name="t('decidiq', 'Email voting')"
			:description="t('decidiq', 'Enable voting by email reply')">
			<div class="form-group form-group--checkbox">
				<input
					id="email_voting_enabled"
					v-model="form.email_voting_enabled"
					type="checkbox"
					:aria-label="t('decidiq', 'Email voting')"
					@change="saveEmailVoting" />
				<label for="email_voting_enabled">{{
					t('decidiq', 'Enable voting by email reply')
				}}</label>
			</div>
		</CnSettingsSection>

		<!-- Citizen-participation instance defaults -->
		<!-- @spec openspec/specs/citizen-participation/spec.md -->
		<CnSettingsSection
			:name="t('decidiq', 'Citizen participation defaults')"
			:description="
				t(
					'decidiq',
					'Defaults applied to new consultations and budget rounds. Staff can override per round.',
				)
			">
			<form
				data-testid="participation-settings"
				@submit.prevent="saveParticipation">
				<div class="form-group">
					<NcSelect
						v-model="form.participation_default_moderation_policy"
						:options="moderationPolicyOptions"
						:inputLabel="t('decidiq', 'Default moderation policy')"
						label="label"
						trackBy="id"
						data-testid="participation-moderation-policy" />
				</div>
				<div class="form-group">
					<label for="participation_catalog">{{
						t('decidiq', 'Default OpenCatalogi catalog (UUID)')
					}}</label>
					<input
						id="participation_catalog"
						v-model="form.participation_catalog"
						type="text"
						data-testid="participation-catalog"
						:placeholder="
							t('decidiq', 'Leave empty to skip catalog routing')
						" />
				</div>
				<div class="form-group">
					<label for="participation_anon_rate_limit">{{
						t('decidiq', 'Anonymous intake rate limit (per hour)')
					}}</label>
					<input
						id="participation_anon_rate_limit"
						v-model="form.participation_anon_rate_limit"
						type="number"
						min="1"
						data-testid="participation-rate-limit"
						placeholder="5" />
				</div>
				<NcButton
					variant="primary"
					type="submit"
					data-testid="participation-save"
					:disabled="savingParticipation">
					{{
						savingParticipation
							? t('decidiq', 'Saving...')
							: t('decidiq', 'Save')
					}}
				</NcButton>
			</form>
		</CnSettingsSection>
	</div>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import ProcessTemplates from '../../components/processTemplates/ProcessTemplates.vue'
import { useSettingsStore } from '../../store/modules/settings.js'

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
				organisatie_modus: null,
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
			savingOrganisationMode: false,
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
			return [
				'Europe/Amsterdam',
				'Europe/Brussels',
				'Europe/Berlin',
				'Europe/Paris',
				'Europe/London',
				'UTC',
			]
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		localeOptions() {
			return [
				{ id: 'nl', label: this.t('decidiq', 'Dutch') },
				{ id: 'en', label: this.t('decidiq', 'English') },
			]
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		currencyOptions() {
			return ['EUR', 'USD', 'GBP', 'CHF']
		},

		/**
		 * The five organisatie_modus values, matching MODE_LABELS in
		 * src/config/modeLabels.js and the `organisatie_modus` whitelist entry in
		 * lib/Service/SettingsService.php. Cosmetic UI hint only — it selects
		 * label vocabulary and drives no authorization decision.
		 *
		 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
		 */
		organisationModeOptions() {
			return [
				{ id: 'gov', label: this.t('decidiq', 'Government (gov)') },
				{ id: 'corp', label: this.t('decidiq', 'Corporate (corp)') },
				{ id: 'assoc', label: this.t('decidiq', 'Association (assoc)') },
				{ id: 'ops', label: this.t('decidiq', 'Operations (ops)') },
				{
					id: 'citizen',
					label: this.t('decidiq', 'Citizen portal (citizen)'),
				},
			]
		},

		/** @spec openspec/specs/citizen-participation/spec.md */
		moderationPolicyOptions() {
			return [
				{
					id: 'pre-moderation',
					label: this.t(
						'decidiq',
						'Pre-moderation (approve before counting)',
					),
				},
				{
					id: 'post-moderation',
					label: this.t(
						'decidiq',
						'Post-moderation (auto-approve authenticated)',
					),
				},
			]
		},
	},

	/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10 */
	created() {
		const settingsStore = useSettingsStore()
		const settings = settingsStore.settings || {}
		this.form.register = settings.register || ''
		// SettingsService::getSettings() defaults this to 'gov', but fall back
		// here too so an instance predating the key still selects a valid option
		// rather than rendering an empty combobox.
		this.form.organisatie_modus =
			this.organisationModeOptions.find(
				(o) => o.id === settings.organisatie_modus,
			) || this.organisationModeOptions[0]
		this.form.ori_endpoint = settings.ori_endpoint || ''
		this.form.email_voting_enabled =
			settings.email_voting_enabled === '1'
			|| settings.email_voting_enabled === true
		this.form.organisation_name = settings.organisation_name || ''
		this.form.organisation_logo = settings.organisation_logo || ''
		this.form.organisation_timezone = settings.organisation_timezone || ''
		this.form.organisation_locale =
			this.localeOptions.find((o) => o.id === settings.organisation_locale)
			|| null
		this.form.organisation_currency = settings.organisation_currency || ''
		this.form.organisation_retention_days =
			settings.organisation_retention_days || ''
		this.form.participation_default_moderation_policy =
			this.moderationPolicyOptions.find(
				(o) => o.id === settings.participation_default_moderation_policy,
			) || this.moderationPolicyOptions[0]
		this.form.participation_catalog = settings.participation_catalog || ''
		this.form.participation_anon_rate_limit =
			settings.participation_anon_rate_limit || ''
	},

	methods: {
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10 */
		async save() {
			this.saving = true
			this.successMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings({
				register: this.form.register,
			})
			if (result) {
				this.successMessage = this.t(
					'decidiq',
					'Settings saved successfully',
				)
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
				organisation_retention_days: String(
					this.form.organisation_retention_days || '',
				),
			})
			if (result) {
				this.organisationMessage = this.t(
					'decidiq',
					'Organization settings saved',
				)
			}
			this.savingOrganisation = false
		},

		/** @spec openspec/specs/citizen-participation/spec.md */
		async saveParticipation() {
			this.savingParticipation = true
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings({
				participation_default_moderation_policy:
					this.form.participation_default_moderation_policy?.id
					|| 'pre-moderation',
				participation_catalog: this.form.participation_catalog || '',
				participation_anon_rate_limit: String(
					this.form.participation_anon_rate_limit || '',
				),
			})
			this.savingParticipation = false
		},

		/** @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting */
		async saveOrganisationMode() {
			this.savingOrganisationMode = true
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings({
				organisatie_modus: this.form.organisatie_modus?.id || 'gov',
			})
			this.savingOrganisationMode = false
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1 */
		async saveOri() {
			this.savingOri = true
			const settingsStore = useSettingsStore()
			await settingsStore.saveSettings({
				ori_endpoint: this.form.ori_endpoint,
			})
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
