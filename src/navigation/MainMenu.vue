<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p1-crud-operations/tasks.md#task-4.1
@spec openspec/changes/p2-motion-and-voting/tasks.md#task-8.4
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
-->
<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('decidesk', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Minutes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4 -->
			<NcAppNavigationItem
				:name="t('decidesk', 'Notulen')"
				:to="{ name: 'Minutes' }">
				<template #icon>
					<FileDocumentOutline :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Decisions — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4 -->
			<NcAppNavigationItem
				:name="t('decidesk', 'Besluiten')"
				:to="{ name: 'Decisions' }">
				<template #icon>
					<CheckDecagramIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Action Items — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4 -->
			<NcAppNavigationItem
				:name="t('decidesk', 'Actiepunten')"
				:to="{ name: 'ActionItems' }">
				<template #icon>
					<CheckboxMarkedOutline :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('decidesk', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
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
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('decidesk', 'Settings')"
					:to="{ name: 'Settings' }">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		BookOpenVariantOutline,
		CheckboxMarkedOutline,
		CheckDecagramIcon,
		Cog,
		FileDocumentOutline,
		GavelIcon,
		ViewDashboard,
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
