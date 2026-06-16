<!-- SPDX-License-Identifier: EUPL-1.2 -->

<!--
 In-app user settings dialog (user-settings spec) — renders the same four
 personal-preference sections as the Nextcloud personal settings panel and
 the /user-settings SPA page.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<NcAppSettingsDialog
		:open="open"
		:show-navigation="true"
		:name="t('decidesk', 'Decidesk settings')"
		@update:open="$emit('update:open', $event)">
		<NcAppSettingsSection
			id="notifications"
			:name="t('decidesk', 'Notifications')">
			<template #icon>
				<BellIcon :size="20" />
			</template>
			<NotificationPreferencesSection :preference="preference" @updated="preference = $event" />
		</NcAppSettingsSection>
		<NcAppSettingsSection
			id="display"
			:name="t('decidesk', 'Display')">
			<template #icon>
				<MonitorIcon :size="20" />
			</template>
			<DisplayPreferencesSection />
		</NcAppSettingsSection>
		<NcAppSettingsSection
			id="delegation"
			:name="t('decidesk', 'Delegation')">
			<template #icon>
				<AccountSwitchIcon :size="20" />
			</template>
			<DelegationSection :preference="preference" @updated="preference = $event" />
		</NcAppSettingsSection>
		<NcAppSettingsSection
			id="communication"
			:name="t('decidesk', 'Communication')">
			<template #icon>
				<EmailIcon :size="20" />
			</template>
			<CommunicationSection :preference="preference" @updated="preference = $event" />
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script>
import { NcAppSettingsDialog, NcAppSettingsSection } from '@nextcloud/vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import MonitorIcon from 'vue-material-design-icons/Monitor.vue'
import AccountSwitchIcon from 'vue-material-design-icons/AccountSwitch.vue'
import EmailIcon from 'vue-material-design-icons/Email.vue'
import NotificationPreferencesSection from '../../components/userSettings/NotificationPreferencesSection.vue'
import DisplayPreferencesSection from '../../components/userSettings/DisplayPreferencesSection.vue'
import DelegationSection from '../../components/userSettings/DelegationSection.vue'
import CommunicationSection from '../../components/userSettings/CommunicationSection.vue'
import { fetchNotificationPreference } from '../../components/userSettings/userPreferences.js'

export default {
	name: 'UserSettings',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		BellIcon,
		MonitorIcon,
		AccountSwitchIcon,
		EmailIcon,
		NotificationPreferencesSection,
		DisplayPreferencesSection,
		DelegationSection,
		CommunicationSection,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			preference: {},
		}
	},
	watch: {
		open: {
			immediate: true,
			handler: 'loadOnOpen',
		},
	},
	methods: {
		/**
		 * Load the preference object the first time the dialog opens.
		 *
		 * @param {boolean} isOpen Whether the dialog just opened.
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async loadOnOpen(isOpen) {
			if (!isOpen || Object.keys(this.preference).length > 0) {
				return
			}
			try {
				this.preference = await fetchNotificationPreference()
			} catch (e) {
				console.error('decidesk: failed to load user preferences', e)
			}
		},
	},
}
</script>
