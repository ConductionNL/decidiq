<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SPA page /apps/decidesk/user-settings — the in-app mount of the personal
 settings sections (user-settings spec). Same four sections as the Nextcloud
 personal settings panel (/settings/user/decidesk); registered via the
 ADR-037 manifest fragment src/manifest.d/user-settings.json.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<div class="user-settings-page" data-testid="user-settings-page">
		<header class="user-settings-page__header">
			<h2>{{ t('decidesk', 'Personal settings') }}</h2>
			<p>{{ t('decidesk', 'Notification, display, delegation and communication preferences for your account.') }}</p>
		</header>

		<NcLoadingIcon v-if="loading" :size="48" />
		<template v-else>
			<NotificationPreferencesSection :preference="preference" @updated="preference = $event" />
			<DisplayPreferencesSection />
			<DelegationSection :preference="preference" @updated="preference = $event" />
			<CommunicationSection :preference="preference" @updated="preference = $event" />
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import NotificationPreferencesSection from '../../components/userSettings/NotificationPreferencesSection.vue'
import DisplayPreferencesSection from '../../components/userSettings/DisplayPreferencesSection.vue'
import DelegationSection from '../../components/userSettings/DelegationSection.vue'
import CommunicationSection from '../../components/userSettings/CommunicationSection.vue'
import { fetchNotificationPreference } from '../../components/userSettings/userPreferences.js'

export default {
	name: 'UserSettingsPage',
	components: {
		NcLoadingIcon,
		NotificationPreferencesSection,
		DisplayPreferencesSection,
		DelegationSection,
		CommunicationSection,
	},
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
		 * Load the session user's preference object once for all sections.
		 *
		 * @spec openspec/specs/user-settings/spec.md
		 */
		async load() {
			try {
				this.preference = await fetchNotificationPreference()
			} catch (e) {
				console.error('decidesk: failed to load user preferences', e)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.user-settings-page {
	padding: 20px;
	max-width: 700px;
}

.user-settings-page__header {
	margin-bottom: 16px;
}

.user-settings-page__header p {
	color: var(--color-text-maxcontrast);
}
</style>
