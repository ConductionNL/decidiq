<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-4.2
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.1
-->
<template>
	<NcAppNavigation>
		<template #list>
			<GlobalSearch />
			<NcAppNavigationItem
				:name="t('decidesk', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Vergaderingen')"
				:to="{ name: 'MeetingList' }">
				<template #icon>
					<CalendarBlank :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Moties')"
				:to="{ name: 'MotionList' }">
				<template #icon>
					<FileDocumentOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Besluiten')"
				:to="{ name: 'DecisionList' }">
				<template #icon>
					<GavelIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Deelnemers')"
				:to="{ name: 'ParticipantList' }">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Bestuursorganen')"
				:to="{ name: 'GovernanceBodyList' }">
				<template #icon>
					<DomainIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings
				v-if="isAdmin"
				:name="t('decidesk', 'Instellingen')">
				<NcAppNavigationItem
					:name="t('decidesk', 'Instellingen')"
					:to="{ name: 'Settings' }">
					<template #icon>
						<CogIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import { useSettingsStore } from '../store/modules/settings.js'

import HomeIcon from 'vue-material-design-icons/Home.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'

/**
 * Main navigation menu with entity routes, search, and settings.
 *
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-4.2
 */
export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		GlobalSearch,
		HomeIcon,
		CalendarBlank,
		FileDocumentOutline,
		GavelIcon,
		AccountGroupOutline,
		DomainIcon,
		CogIcon,
	},

	computed: {
		isAdmin() {
			return useSettingsStore().getIsAdmin
		},
	},
}
</script>
