<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings — Display preferences section (user-settings spec).

 Default landing view, items per page, and date format. Stored as IConfig
 user values via the existing per-user /api/preferences/{key} endpoints.
 Interface language is owned by Nextcloud core (personal settings), so this
 section links there instead of duplicating it.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<div class="user-settings-section" data-testid="display-preferences-section">
		<h3>{{ t('decidesk', 'Display preferences') }}</h3>
		<p class="user-settings-section__hint">
			{{
				t(
					'decidesk',
					'Control how Decidesk presents itself for your account.',
				)
			}}
		</p>

		<div class="user-settings-section__field">
			<NcSelect
				v-model="defaultView"
				:input-label="t('decidesk', 'Default view')"
				:options="viewOptions"
				label="label"
				:clearable="false"
				data-testid="display-default-view" />
		</div>

		<div class="user-settings-section__field">
			<NcSelect
				v-model="itemsPerPage"
				:input-label="t('decidesk', 'Items per page')"
				:options="itemsPerPageOptions"
				:clearable="false"
				data-testid="display-items-per-page" />
		</div>

		<div class="user-settings-section__field">
			<NcSelect
				v-model="dateFormat"
				:input-label="t('decidesk', 'Date format')"
				:options="dateFormatOptions"
				label="label"
				:clearable="false"
				data-testid="display-date-format" />
			<p class="user-settings-section__hint">
				{{ t('decidesk', 'Example: {example}', { example: dateExample }) }}
			</p>
		</div>

		<p class="user-settings-section__hint">
			{{
				t(
					'decidesk',
					'Interface language follows your Nextcloud account language.',
				)
			}}
			<a :href="languageSettingsUrl" target="_blank" rel="noopener noreferrer">
				{{ t('decidesk', 'Change it in your personal settings.') }}
			</a>
		</p>

		<div class="user-settings-section__actions">
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="display-preferences-save"
				@click="save">
				{{
					saving
						? t('decidesk', 'Saving …')
						: t('decidesk', 'Save display preferences')
				}}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="saved" type="success">
			{{ t('decidesk', 'Display preferences saved.') }}
		</NcNoteCard>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	DATE_FORMAT_OPTIONS,
	DISPLAY_DEFAULTS,
	fetchDisplayPreference,
	formatDate,
	saveDisplayPreference,
} from './userPreferences.js'

export default {
	name: 'DisplayPreferencesSection',
	components: {
		NcButton,
		NcNoteCard,
		NcSelect,
	},
	data() {
		return {
			defaultView: null,
			itemsPerPage: DISPLAY_DEFAULTS['items-per-page'],
			dateFormat: null,
			saving: false,
			saved: false,
			error: null,
		}
	},
	computed: {
		/** @spec openspec/specs/user-settings/spec.md */
		viewOptions() {
			return [
				{ id: 'dashboard', label: this.t('decidesk', 'Dashboard') },
				{ id: 'meetings', label: this.t('decidesk', 'Meetings') },
				{ id: 'decisions', label: this.t('decidesk', 'Decisions') },
			]
		},
		/** @spec openspec/specs/user-settings/spec.md */
		itemsPerPageOptions() {
			return ['10', '25', '50', '100']
		},
		/** @spec openspec/specs/user-settings/spec.md */
		dateFormatOptions() {
			return DATE_FORMAT_OPTIONS.map((id) => ({
				id,
				label:
					id === 'locale'
						? this.t('decidesk', 'Nextcloud locale (default)')
						: id,
			}))
		},
		/** @spec openspec/specs/user-settings/spec.md */
		dateExample() {
			return formatDate(new Date(), this.dateFormat?.id || 'locale')
		},
		/** @spec openspec/specs/user-settings/spec.md */
		languageSettingsUrl() {
			return generateUrl('/settings/user')
		},
	},
	async created() {
		await this.load()
	},
	methods: {
		/**
		 * Load the three display preferences from the per-user endpoints.
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async load() {
			try {
				const [view, perPage, format] = await Promise.all([
					fetchDisplayPreference('default-view'),
					fetchDisplayPreference('items-per-page'),
					fetchDisplayPreference('date-format'),
				])
				this.defaultView =
					this.viewOptions.find((o) => o.id === view)
					|| this.viewOptions[0]
				this.itemsPerPage = this.itemsPerPageOptions.includes(perPage)
					? perPage
					: DISPLAY_DEFAULTS['items-per-page']
				this.dateFormat =
					this.dateFormatOptions.find((o) => o.id === format)
					|| this.dateFormatOptions[0]
			} catch (e) {
				this.defaultView = this.viewOptions[0]
				this.dateFormat = this.dateFormatOptions[0]
			}
		},
		/**
		 * Persist the three display preferences.
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async save() {
			this.saving = true
			this.saved = false
			this.error = null
			try {
				await Promise.all([
					saveDisplayPreference(
						'default-view',
						this.defaultView?.id || 'dashboard',
					),
					saveDisplayPreference(
						'items-per-page',
						this.itemsPerPage || DISPLAY_DEFAULTS['items-per-page'],
					),
					saveDisplayPreference(
						'date-format',
						this.dateFormat?.id || 'locale',
					),
				])
				this.saved = true
			} catch (e) {
				this.error = e.message || this.t('decidesk', 'Saving failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.user-settings-section {
	margin-bottom: 24px;
}

.user-settings-section__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.user-settings-section__field {
	margin: 12px 0;
	max-width: 400px;
}

.user-settings-section__actions {
	margin-top: 12px;
}
</style>
