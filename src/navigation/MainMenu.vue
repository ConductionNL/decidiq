<!-- SPDX-License-Identifier: EUPL-1.2 -->
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
				:to="{ name: 'GovernanceBodies' }">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Meetings')"
				:to="{ name: 'Meetings' }">
				<template #icon>
					<CalendarClock :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Participants')"
				:to="{ name: 'Participants' }">
				<template #icon>
					<AccountOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Agenda Items')"
				:to="{ name: 'AgendaItems' }">
				<template #icon>
					<FormatListNumbered :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('decidesk', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		AccountGroupOutline,
		AccountOutline,
		CalendarClock,
		CogIcon,
		FormatListNumbered,
		HomeIcon,
	},
}
</script>
