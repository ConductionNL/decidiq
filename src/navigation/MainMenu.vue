<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-4.1
-->
<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('decidesk', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Governance Bodies')"
				:to="{ name: 'GovernanceBodyList' }">
				<template #icon>
					<DomainIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Meetings')"
				:to="{ name: 'MeetingList' }">
				<template #icon>
					<CalendarBlank :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Participants')"
				:to="{ name: 'ParticipantList' }">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Agenda Items')"
				:to="{ name: 'AgendaItemList' }">
				<template #icon>
					<FormatListBulleted :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('decidesk', 'Settings')"
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
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'

/**
 * Main sidebar navigation with links to all p1 entity views.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-4.1
 */
export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		AccountGroupOutline,
		CalendarBlank,
		CogIcon,
		DomainIcon,
		FormatListBulleted,
		HomeIcon,
	},
}
</script>
