<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings — Notification preferences section (user-settings spec).

 Per-event toggles, two independent delivery-channel switches (mapped onto
 the deliveryMethod enum) and meeting-reminder timing checkboxes.

 ⚠️ @nextcloud/vue 9 contract for NcCheckboxRadioSwitch:
   - the state prop is `model-value` and the event is `update:model-value`.
     The Vue-2-era `:checked` / `@update:checked` pair is NOT declared on the
     v9 component, so it falls through to `$attrs` — `checked` lands on the
     raw <input> (making the native control look right) while the component's
     own `modelValue` stays at its `false` default, and `onUpdate:checked` is
     registered on the <input> as a listener for an event the DOM never
     fires. Result: every switch renders visually "off" and no click is ever
     handled. That is exactly what shipped before this was corrected.
   - the component sets `inheritAttrs: false` and merges `$attrs` onto the
     <input>, so the `data-testid` below IS the input element — not a wrapper
     around it. The e2e suite targets it accordingly.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<div
		class="user-settings-section"
		data-testid="notification-preferences-section">
		<h3>{{ t('decidesk', 'Notification preferences') }}</h3>
		<p class="user-settings-section__hint">
			{{
				t(
					'decidesk',
					'Choose which Decidesk events notify you and how they are delivered.',
				)
			}}
		</p>

		<fieldset class="user-settings-section__group">
			<legend>{{ t('decidesk', 'Notify me about') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="event in eventToggles"
				:key="event.key"
				type="switch"
				:model-value="form[event.key]"
				:data-testid="`notification-toggle-${event.key}`"
				@update:model-value="form[event.key] = $event">
				{{ event.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<fieldset class="user-settings-section__group">
			<legend>{{ t('decidesk', 'Delivery channels') }}</legend>
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="channels.inApp"
				data-testid="channel-in-app"
				@update:model-value="channels.inApp = $event">
				{{ t('decidesk', 'Nextcloud notification') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="channels.email"
				data-testid="channel-email"
				@update:model-value="channels.email = $event">
				{{ t('decidesk', 'Email') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard v-if="channelError" type="warning">
				{{
					t(
						'decidesk',
						'Keep at least one delivery channel enabled. Use the per-event switches to mute specific notifications.',
					)
				}}
			</NcNoteCard>
		</fieldset>

		<fieldset class="user-settings-section__group">
			<legend>{{ t('decidesk', 'Meeting reminder timing') }}</legend>
			<p class="user-settings-section__hint">
				{{
					t('decidesk', 'Default: 24 hours and 1 hour before the meeting.')
				}}
			</p>
			<NcCheckboxRadioSwitch
				v-for="time in reminderOptions"
				:key="time.value"
				:model-value="form.reminderTimes.includes(time.value)"
				:disabled="!form.meetingReminder"
				:data-testid="`reminder-time-${time.value}`"
				@update:model-value="toggleReminderTime(time.value, $event)">
				{{ time.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<div class="user-settings-section__actions">
			<NcButton
				variant="primary"
				:disabled="saving || channelError"
				data-testid="notification-preferences-save"
				@click="save">
				{{
					saving
						? t('decidesk', 'Saving …')
						: t('decidesk', 'Save notification preferences')
				}}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="saved" type="success">
			{{ t('decidesk', 'Notification preferences saved.') }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import {
	DEFAULT_REMINDER_TIMES,
	REMINDER_TIME_OPTIONS,
	channelsToDeliveryMethod,
	deliveryMethodToChannels,
	saveNotificationPreference,
} from './userPreferences.js'

export default {
	name: 'NotificationPreferencesSection',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
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
			form: {
				meetingCreated: true,
				votingOpened: true,
				decisionPublished: true,
				taskAssigned: true,
				commentMention: true,
				meetingReminder: true,
				reminderTimes: [...DEFAULT_REMINDER_TIMES],
			},
			channels: { inApp: true, email: false },
			saving: false,
			saved: false,
			error: null,
		}
	},
	computed: {
		/** @spec openspec/specs/user-settings/spec.md */
		eventToggles() {
			return [
				{
					key: 'meetingCreated',
					label: this.t('decidesk', 'Meeting scheduled'),
				},
				{ key: 'votingOpened', label: this.t('decidesk', 'Pending vote') },
				{
					key: 'decisionPublished',
					label: this.t('decidesk', 'Decision published'),
				},
				{
					key: 'taskAssigned',
					label: this.t('decidesk', 'Action item assigned'),
				},
				{
					key: 'commentMention',
					label: this.t('decidesk', 'Mentioned in a comment'),
				},
				{
					key: 'meetingReminder',
					label: this.t('decidesk', 'Meeting reminder'),
				},
			]
		},
		/** @spec openspec/specs/user-settings/spec.md */
		reminderOptions() {
			const labels = {
				'1h': this.t('decidesk', '1 hour before'),
				'4h': this.t('decidesk', '4 hours before'),
				'24h': this.t('decidesk', '24 hours before'),
				'48h': this.t('decidesk', '48 hours before'),
				'1w': this.t('decidesk', '1 week before'),
			}
			return REMINDER_TIME_OPTIONS.map((value) => ({
				value,
				label: labels[value] || value,
			}))
		},
		/** @spec openspec/specs/user-settings/spec.md */
		channelError() {
			return !this.channels.inApp && !this.channels.email
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
			for (const key of [
				'meetingCreated',
				'votingOpened',
				'decisionPublished',
				'taskAssigned',
				'commentMention',
				'meetingReminder',
			]) {
				if (typeof pref[key] === 'boolean') {
					this.form[key] = pref[key]
				}
			}
			if (Array.isArray(pref.reminderTimes) && pref.reminderTimes.length > 0) {
				this.form.reminderTimes = [...pref.reminderTimes]
			}
			this.channels = deliveryMethodToChannels(pref.deliveryMethod || 'in-app')
		},
		/**
		 * Toggle one reminder-time token in the form.
		 *
		 * @param {string} value The reminder token (e.g. '48h').
		 * @param {boolean} checked Whether it is now enabled.
		 * @spec openspec/specs/user-settings/spec.md
		 */
		toggleReminderTime(value, checked) {
			const set = new Set(this.form.reminderTimes)
			if (checked) {
				set.add(value)
			} else {
				set.delete(value)
			}
			this.form.reminderTimes = REMINDER_TIME_OPTIONS.filter((token) =>
				set.has(token),
			)
		},
		/**
		 * Persist the notification preferences via the per-user endpoint.
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async save() {
			const deliveryMethod = channelsToDeliveryMethod(this.channels)
			if (deliveryMethod === null) {
				return
			}
			if (this.form.reminderTimes.length === 0) {
				this.form.reminderTimes = [...DEFAULT_REMINDER_TIMES]
			}
			this.saving = true
			this.saved = false
			this.error = null
			try {
				const saved = await saveNotificationPreference({
					...this.form,
					deliveryMethod,
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

.user-settings-section__group {
	border: 0;
	margin: 12px 0;
	padding: 0;
}

.user-settings-section__group legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.user-settings-section__actions {
	margin-top: 12px;
}
</style>
