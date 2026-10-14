<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings — includes ORI endpoint and email voting toggle.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
-->
<template>
	<div>
		<!-- Board DcBeheer, section Organisatie: the kind of organisation as
		     radio cards, then the register. Each saves the one field it changed
		     through POST /api/settings (nextcloud-vue widget `save`), so an
		     admin page without an object id can save it.
		     @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting -->
		<CnSettingsSection
			id="section-organisation-mode"
			:name="t('decidiq', 'Organisation')"
			:description="t('decidiq', 'Sets the words and defaults across the whole app.')"
			data-testid="organisation-mode-settings">
			<CnRadioCardsWidget
				:content="organisationModeContent"
				:objectData="settingsStore.settings || {}"
				data-testid="organisation-mode"
				@saved="settingsStore.fetchSettings()" />
			<CnSettingsFormWidget
				:content="registerContent"
				:objectData="settingsStore.settings || {}"
				data-testid="organisation-register"
				@saved="settingsStore.fetchSettings()" />
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

		<!-- ORI publication settings -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10.1 -->
		<!-- The id is the anchor lib/Settings/connections.json links to (adopt-connection-registry). -->
		<CnSettingsSection
			id="section-ori"
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
			id="section-email-voting"
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
import { CnRadioCardsWidget, CnSettingsFormWidget, CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import ProcessTemplates from '../../components/processTemplates/ProcessTemplates.vue'
import { useSettingsStore } from '../../store/modules/settings.js'

/** Where the settings widgets save: the app config endpoint, with the changed fields only. */
const SETTINGS_SAVE = { url: '/apps/decidiq/api/settings', method: 'POST', payload: '{changes}' }

export default {
	name: 'Settings',
	components: {
		NcButton,
		NcSelect,
		CnRadioCardsWidget,
		CnSettingsFormWidget,
		CnSettingsSection,
		ProcessTemplates,
	},

	data() {
		return {
			form: {
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

			savingOri: false,
			savingOrganisation: false,
			savingParticipation: false,
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

		/** @return {object} The settings store, whose `settings` the widgets read. */
		settingsStore() {
			return useSettingsStore()
		},

		/**
		 * The five organisatie_modus values as radio cards, matching MODE_LABELS
		 * in src/config/modeLabels.js and the `organisatie_modus` whitelist entry
		 * in lib/Service/SettingsService.php. Cosmetic UI hint only: it selects
		 * label vocabulary and drives no authorization decision. The board draws
		 * four; the citizen portal is kept as the fifth.
		 *
		 * @return {object} The radio-cards widget content.
		 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
		 */
		organisationModeContent() {
			return {
				field: 'organisatie_modus',
				label: this.t('decidiq', 'Kind of organisation'),
				columns: 2,
				save: SETTINGS_SAVE,
				options: [
					{ value: 'gov', label: this.t('decidiq', 'Municipality'), description: this.t('decidiq', 'Council, executive, committees, clerk') },
					{ value: 'assoc', label: this.t('decidiq', 'Association or owners\' association'), description: this.t('decidiq', 'Board, members, general meeting') },
					{ value: 'corp', label: this.t('decidiq', 'Company'), description: this.t('decidiq', 'Executive board, supervisory board') },
					{ value: 'ops', label: this.t('decidiq', 'Team'), description: this.t('decidiq', 'Meetings, decisions, action items') },
					{ value: 'citizen', label: this.t('decidiq', 'Citizen portal'), description: this.t('decidiq', 'Residents follow decisions and take part') },
				],
			}
		},

		/**
		 * The register field, saved on its own.
		 *
		 * @return {object} The settings-form widget content.
		 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
		 */
		registerContent() {
			return {
				columns: 1,
				save: SETTINGS_SAVE,
				saveLabel: this.t('decidiq', 'Save'),
				fields: [
					{ key: 'register', label: this.t('decidiq', 'Register'), type: 'text', placeholder: this.t('decidiq', 'OpenRegister register ID'), hint: this.t('decidiq', 'Reading it in again after an update is under Advanced.') },
				],
			}
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
