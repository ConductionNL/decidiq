<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings — Delegation and absence section (user-settings spec).

 Configure a delegate who receives this user's Decidesk notifications during
 an absence period. The delegation expires automatically on the end date and
 NEVER grants voting rights — a formal proxy (volmacht) is required for
 voting (granted per voting round via the existing proxy process).

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<div class="user-settings-section" data-testid="delegation-section">
		<h3>{{ t('decidesk', 'Delegation and absence') }}</h3>
		<p class="user-settings-section__hint">
			{{
				t(
					'decidesk',
					'During the configured period your delegate receives your Decidesk notifications and can follow your pending votes and action items.',
				)
			}}
		</p>

		<div class="user-settings-section__field">
			<NcSelect
				v-model="delegate"
				:inputLabel="t('decidesk', 'Delegate')"
				:options="delegateOptions"
				label="label"
				:loading="searching"
				data-testid="delegation-delegate"
				@search="onDelegateSearch" />
		</div>

		<div class="user-settings-section__field">
			<label for="decidesk-delegation-from">{{
				t('decidesk', 'Absent from')
			}}</label>
			<NcDateTimePickerNative
				id="decidesk-delegation-from"
				v-model="delegationFrom"
				type="date"
				data-testid="delegation-from" />
		</div>

		<div class="user-settings-section__field">
			<label for="decidesk-delegation-until">{{
				t('decidesk', 'Absent until (delegation expires automatically)')
			}}</label>
			<NcDateTimePickerNative
				id="decidesk-delegation-until"
				v-model="delegationUntil"
				type="date"
				data-testid="delegation-until" />
		</div>

		<NcNoteCard type="info" data-testid="delegation-proxy-note">
			{{
				t(
					'decidesk',
					'Delegation does not include voting rights. A formal proxy (volmacht) is required for voting.',
				)
			}}
			{{
				t(
					'decidesk',
					'Proxies are granted per voting round from the voting panel.',
				)
			}}
		</NcNoteCard>

		<NcNoteCard v-if="validationError" type="warning">
			{{ validationError }}
		</NcNoteCard>

		<div class="user-settings-section__actions">
			<NcButton
				variant="primary"
				:disabled="saving || !!validationError"
				data-testid="delegation-save"
				@click="save">
				{{
					saving
						? t('decidesk', 'Saving …')
						: t('decidesk', 'Save delegation')
				}}
			</NcButton>
			<NcButton
				v-if="hasDelegation"
				variant="tertiary"
				:disabled="saving"
				data-testid="delegation-clear"
				@click="clear">
				{{ t('decidesk', 'Clear delegation') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="saved" type="success">
			{{ t('decidesk', 'Delegation saved.') }}
		</NcNoteCard>
	</div>
</template>

<script>
import {
	NcButton,
	NcDateTimePickerNative,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import {
	saveNotificationPreference,
	searchDelegateUsers,
	validateDelegation,
} from './userPreferences.js'

/**
 * Format a Date as YYYY-MM-DD ('' for null).
 *
 * @param {Date|null} date The date.
 * @return {string} ISO date or ''.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
function toIsoDate(date) {
	if (!(date instanceof Date) || isNaN(date.getTime())) {
		return ''
	}
	const mm = String(date.getMonth() + 1).padStart(2, '0')
	const dd = String(date.getDate()).padStart(2, '0')
	return `${date.getFullYear()}-${mm}-${dd}`
}

export default {
	name: 'DelegationSection',
	components: {
		NcButton,
		NcDateTimePickerNative,
		NcNoteCard,
		NcSelect,
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
			delegate: null,
			delegateOptions: [],
			delegationFrom: null,
			delegationUntil: null,
			searching: false,
			searchTimer: null,
			saving: false,
			saved: false,
			error: null,
		}
	},

	computed: {
		/** @spec openspec/specs/user-settings/spec.md */
		hasDelegation() {
			return !!(this.delegate?.id || this.preference?.delegate)
		},

		/** @spec openspec/specs/user-settings/spec.md */
		validationError() {
			const code = validateDelegation({
				delegate: this.delegate?.id || '',
				delegationFrom: toIsoDate(this.delegationFrom),
				delegationUntil: toIsoDate(this.delegationUntil),
			})
			if (code === 'expiry-required') {
				return this.t(
					'decidesk',
					'A delegation needs an end date — it expires automatically.',
				)
			}
			if (code === 'inverted-period') {
				return this.t(
					'decidesk',
					'The end date must not be before the start date.',
				)
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
			if (pref.delegate) {
				this.delegate = { id: pref.delegate, label: pref.delegate }
			}
			this.delegationFrom = pref.delegationFrom
				? new Date(`${pref.delegationFrom}T00:00:00`)
				: null
			this.delegationUntil = pref.delegationUntil
				? new Date(`${pref.delegationUntil}T00:00:00`)
				: null
		},

		/**
		 * Debounced delegate search against the sharees endpoint.
		 *
		 * @param {string} search The typed search term.
		 * @spec openspec/specs/user-settings/spec.md
		 */
		onDelegateSearch(search) {
			clearTimeout(this.searchTimer)
			if (!search || search.length < 2) {
				return
			}
			this.searchTimer = setTimeout(async () => {
				this.searching = true
				try {
					this.delegateOptions = await searchDelegateUsers(search)
				} finally {
					this.searching = false
				}
			}, 300)
		},

		/**
		 * Persist the delegation (delegate + period) via the per-user endpoint.
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
					delegate: this.delegate?.id || '',
					delegationFrom: toIsoDate(this.delegationFrom),
					delegationUntil: toIsoDate(this.delegationUntil),
				})
				this.saved = true
				this.$emit('updated', saved)
			} catch (e) {
				this.error = e.message || this.t('decidesk', 'Saving failed.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Clear the delegation entirely (empty delegate clears the period too).
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async clear() {
			this.delegate = null
			this.delegationFrom = null
			this.delegationUntil = null
			this.saving = true
			this.saved = false
			this.error = null
			try {
				const saved = await saveNotificationPreference({ delegate: '' })
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

.user-settings-section__field label {
	display: block;
	margin-bottom: 4px;
}

.user-settings-section__actions {
	margin-top: 12px;
	display: flex;
	gap: 8px;
}
</style>
