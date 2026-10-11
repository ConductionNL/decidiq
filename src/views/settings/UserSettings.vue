<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The sections of decidiq's user settings dialog (the NcAppSettingsDialog that
 CnAppRoot hosts and the Personal settings entry in the settings foldout
 opens).

 Every per-person preference lives here. Decidiq has no page under Nextcloud's
 personal settings and no /user-settings app page any more (Ruben, 11 Oct
 2026: user configuration goes into the user settings dialog, app
 configuration into the admin settings). The five decidiq sections below are
 the ones those two pages carried.

 THE LIBRARY'S OWN SECTIONS ARE REPEATED HERE ON PURPOSE. Filling CnAppRoot's
 #user-settings slot replaces its fallback content entirely, and nothing
 reports that: the notifications pane, credentials and the walkthrough replay
 would have vanished from the dialog in silence. App.vue hands in the
 walkthrough state CnAppRoot owns.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<NcAppSettingsSection
		id="decidiq-notifications"
		:name="t('decidiq', 'Notification preferences')">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NotificationPreferencesSection
			v-else
			:preference="preference"
			@updated="preference = $event" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="decidiq-display"
		:name="t('decidiq', 'Display preferences')">
		<DisplayPreferencesSection />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="decidiq-delegation"
		:name="t('decidiq', 'Delegation and absence')">
		<NcLoadingIcon v-if="loading" :size="32" />
		<DelegationSection
			v-else
			:preference="preference"
			@updated="preference = $event" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="decidiq-communication"
		:name="t('decidiq', 'Communication preferences')">
		<NcLoadingIcon v-if="loading" :size="32" />
		<CommunicationSection
			v-else
			:preference="preference"
			@updated="preference = $event" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="decidiq-subscriptions"
		:name="t('decidiq', 'Subscriptions')">
		<SubscriptionsSection />
	</NcAppSettingsSection>

	<!-- The library's pane over OpenRegister's notification rules. It renders
	     its own NcAppSettingsSection. -->
	<CnNotificationPreferences />

	<NcAppSettingsSection
		id="credentials"
		:name="t('decidiq', 'Credentials')">
		<CnCredentials
			scope="personal"
			appId="decidiq"
			:appName="(manifest && manifest.name) || 'Decidiq'"
			:appCredentials="(manifest && manifest.credentials) || []" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		v-if="walkthrough"
		id="cn-walkthrough"
		:name="walkthrough.sectionName">
		<p class="decidiq-user-settings__hint">
			{{ walkthrough.hint }}
		</p>
		<div class="decidiq-user-settings__actions">
			<NcButton
				v-if="walkthrough.inProgress"
				variant="primary"
				data-testid="cn-walkthrough-continue"
				@click="$emit('continueWalkthrough')">
				{{ walkthrough.continueLabel }}
			</NcButton>
			<NcButton
				variant="secondary"
				data-testid="cn-walkthrough-restart"
				@click="$emit('restartWalkthrough')">
				{{ walkthrough.restartLabel }}
			</NcButton>
		</div>
	</NcAppSettingsSection>
</template>

<script>
import { CnCredentials, CnNotificationPreferences } from '@conduction/nextcloud-vue'
import { NcAppSettingsSection, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CommunicationSection from '../../components/userSettings/CommunicationSection.vue'
import DelegationSection from '../../components/userSettings/DelegationSection.vue'
import DisplayPreferencesSection from '../../components/userSettings/DisplayPreferencesSection.vue'
import NotificationPreferencesSection from '../../components/userSettings/NotificationPreferencesSection.vue'
import SubscriptionsSection from '../../components/userSettings/SubscriptionsSection.vue'
import { fetchNotificationPreference } from '../../components/userSettings/userPreferences.js'

export default {
	name: 'UserSettings',

	components: {
		CnCredentials,
		CnNotificationPreferences,
		CommunicationSection,
		DelegationSection,
		DisplayPreferencesSection,
		NcAppSettingsSection,
		NcButton,
		NcLoadingIcon,
		NotificationPreferencesSection,
		SubscriptionsSection,
	},

	props: {
		/**
		 * The app manifest, for the credentials it declares.
		 */
		manifest: {
			type: Object,
			default: null,
		},

		/**
		 * The walkthrough replay CnAppRoot would have drawn, or null when the
		 * app declares no tour. Labels come from CnAppRoot so the wording stays
		 * the library's.
		 *
		 * @type {{sectionName: string, hint: string, inProgress: boolean, continueLabel: string, restartLabel: string}|null}
		 */
		walkthrough: {
			type: Object,
			default: null,
		},
	},

	emits: ['continueWalkthrough', 'restartWalkthrough'],

	data() {
		return {
			preference: {},
			loading: true,
		}
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Load the session user's preference object once for the three
		 * sections that share it.
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async load() {
			try {
				this.preference = await fetchNotificationPreference()
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('decidiq: failed to load user preferences', e)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-user-settings__hint {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
}

.decidiq-user-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
