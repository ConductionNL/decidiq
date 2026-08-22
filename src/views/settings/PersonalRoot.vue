<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Personal settings root — mounted by templates/settings/personal.php into the
 Nextcloud personal settings (/settings/user/decidesk) per the user-settings
 spec's OCP\Settings\ISettings acceptance criterion.

 @spec openspec/specs/user-settings/spec.md
-->
<template>
	<div
		class="decidesk-personal-settings section"
		data-testid="decidesk-personal-settings">
		<h2>{{ t('decidesk', 'Decidiq personal settings') }}</h2>
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<NotificationPreferencesSection
				:preference="preference"
				@updated="preference = $event" />
			<DisplayPreferencesSection />
			<DelegationSection
				:preference="preference"
				@updated="preference = $event" />
			<CommunicationSection
				:preference="preference"
				@updated="preference = $event" />
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import CommunicationSection from '../../components/userSettings/CommunicationSection.vue'
import DelegationSection from '../../components/userSettings/DelegationSection.vue'
import DisplayPreferencesSection from '../../components/userSettings/DisplayPreferencesSection.vue'
import NotificationPreferencesSection from '../../components/userSettings/NotificationPreferencesSection.vue'
import { fetchNotificationPreference } from '../../components/userSettings/userPreferences.js'

export default {
	name: 'PersonalRoot',
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
.decidesk-personal-settings {
	max-width: 700px;
}
</style>
