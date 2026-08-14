<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings — Communication preferences section (user-settings spec).

 Governance e-mail override (default: the Nextcloud account email), urgent
 phone number, and preferred language for governance communications.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<div class="user-settings-section" data-testid="communication-section">
		<h3>{{ t('decidesk', 'Communication preferences') }}</h3>
		<p class="user-settings-section__hint">
			{{
				t(
					'decidesk',
					'Where Decidesk sends governance communications such as convocations, minutes and reminders.',
				)
			}}
		</p>

		<div class="user-settings-section__field">
			<NcTextField
				v-model="governanceEmail"
				:label="t('decidesk', 'Governance email')"
				:placeholder="accountEmailPlaceholder"
				type="email"
				data-testid="communication-email" />
			<p class="user-settings-section__hint">
				{{
					t('decidesk', 'Leave empty to use your Nextcloud account email.')
				}}
			</p>
		</div>

		<div class="user-settings-section__field">
			<NcTextField
				v-model="urgentPhone"
				:label="t('decidesk', 'Phone for urgent matters')"
				type="tel"
				data-testid="communication-phone" />
		</div>

		<div class="user-settings-section__field">
			<NcSelect
				v-model="language"
				:inputLabel="t('decidesk', 'Preferred language for communications')"
				:options="languageOptions"
				label="label"
				:clearable="false"
				data-testid="communication-language" />
		</div>

		<NcNoteCard v-if="validationError" type="warning">
			{{ validationError }}
		</NcNoteCard>

		<div class="user-settings-section__actions">
			<NcButton
				variant="primary"
				:disabled="saving || !!validationError"
				data-testid="communication-save"
				@click="save">
				{{
					saving
						? t('decidesk', 'Saving …')
						: t('decidesk', 'Save communication preferences')
				}}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="saved" type="success">
			{{ t('decidesk', 'Communication preferences saved.') }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	COMMUNICATION_LANGUAGES,
	isValidEmail,
	saveNotificationPreference,
} from './userPreferences.js'

export default {
	name: 'CommunicationSection',
	components: {
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The defaults-merged preference object loaded by the parent mount. */
		preference: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			governanceEmail: '',
			urgentPhone: '',
			language: null,
			saving: false,
			saved: false,
			error: null,
		}
	},

	computed: {
		/** @spec openspec/specs/user-settings/spec.md */
		languageOptions() {
			const names = {
				nl: this.t('decidesk', 'Dutch'),
				en: this.t('decidesk', 'English'),
				de: this.t('decidesk', 'German'),
				fr: this.t('decidesk', 'French'),
				es: this.t('decidesk', 'Spanish'),
				it: this.t('decidesk', 'Italian'),
			}
			return [
				{ id: '', label: this.t('decidesk', 'Nextcloud locale (default)') },
				...COMMUNICATION_LANGUAGES.map((id) => ({
					id,
					label: names[id] || id,
				})),
			]
		},

		/** @spec openspec/specs/user-settings/spec.md */
		accountEmailPlaceholder() {
			return (
				this.preference?.accountEmail
				|| this.t('decidesk', 'Your Nextcloud account email')
			)
		},

		/** @spec openspec/specs/user-settings/spec.md */
		validationError() {
			if (this.governanceEmail && !isValidEmail(this.governanceEmail)) {
				return this.t('decidesk', 'Enter a valid email address.')
			}
			return null
		},
	},

	watch: {
		preference: {
			immediate: true,
			handler: 'applyPreference',
		},
	},

	methods: {
		/**
		 * Hydrate the form from the loaded preference object.
		 *
		 * @param {object} pref The defaults-merged preference object.
		 * @spec openspec/specs/user-settings/spec.md
		 */
		applyPreference(pref) {
			if (!pref || Object.keys(pref).length === 0) {
				return
			}
			this.governanceEmail = pref.governanceEmail || ''
			this.urgentPhone = pref.urgentPhone || ''
			this.language =
				this.languageOptions.find(
					(o) => o.id === (pref.communicationLanguage || ''),
				) || this.languageOptions[0]
		},

		/**
		 * Persist the communication preferences via the per-user endpoint.
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async save() {
			if (this.validationError) {
				return
			}
			this.saving = true
			this.saved = false
			this.error = null
			try {
				const saved = await saveNotificationPreference({
					governanceEmail: this.governanceEmail || '',
					urgentPhone: this.urgentPhone || '',
					communicationLanguage: this.language?.id || '',
				})
				this.saved = true
				this.$emit('updated', saved)
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
