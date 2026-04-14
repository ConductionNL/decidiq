<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-4.1
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-8.4
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
				:to="{ name: 'GovernanceBodies' }">
				<template #icon>
					<DomainIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Meetings')"
				:to="{ name: 'Meetings' }">
				<template #icon>
					<CalendarBlankIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Participants')"
				:to="{ name: 'Participants' }">
				<template #icon>
					<AccountGroupIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Agenda Items')"
				:to="{ name: 'AgendaItems' }">
				<template #icon>
					<FormatListNumberedIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<!-- Motions navigation item -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-8.4 -->
			<NcAppNavigationItem
				:name="t('decidesk', 'Motions')"
				:to="{ name: 'Motions' }">
				<template #icon>
					<GavelIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings :name="t('decidesk', 'Settings')">
				<NcAppNavigationItem
					:name="t('decidesk', 'Settings')"
					:to="{ name: 'Settings' }" />
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarBlankIcon from 'vue-material-design-icons/CalendarBlank.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import FormatListNumberedIcon from 'vue-material-design-icons/FormatListNumbered.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		AccountGroupIcon,
		CalendarBlankIcon,
		DomainIcon,
		FormatListNumberedIcon,
		GavelIcon,
		HomeIcon,
	},
}
</script>
