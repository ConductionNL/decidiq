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

			<!-- Governance -->
			<NcAppNavigationCaption :name="t('decidesk', 'Governance')" />
			<NcAppNavigationItem
				:name="t('decidesk', 'Bodies')"
				:to="{ name: 'GovernanceBodyIndex' }">
				<template #icon>
					<DomainIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Participants')"
				:to="{ name: 'ParticipantIndex' }">
				<template #icon>
					<AccountGroupIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Meetings -->
			<NcAppNavigationCaption :name="t('decidesk', 'Meetings')" />
			<NcAppNavigationItem
				:name="t('decidesk', 'Meetings')"
				:to="{ name: 'MeetingIndex' }">
				<template #icon>
					<CalendarIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Agenda items')"
				:to="{ name: 'AgendaItemIndex' }">
				<template #icon>
					<FormatListIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Deliberation -->
			<NcAppNavigationCaption :name="t('decidesk', 'Deliberation')" />
			<NcAppNavigationItem
				:name="t('decidesk', 'Motions')"
				:to="{ name: 'MotionIndex' }">
				<template #icon>
					<TextBoxIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Amendments')"
				:to="{ name: 'AmendmentIndex' }">
				<template #icon>
					<PencilBoxIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Voting rounds')"
				:to="{ name: 'VotingRoundIndex' }">
				<template #icon>
					<VoteIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Outcomes -->
			<NcAppNavigationCaption :name="t('decidesk', 'Outcomes')" />
			<NcAppNavigationItem
				:name="t('decidesk', 'Decisions')"
				:to="{ name: 'DecisionIndex' }">
				<template #icon>
					<GavelIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Action items')"
				:to="{ name: 'ActionItemIndex' }">
				<template #icon>
					<CheckboxIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Minutes')"
				:to="{ name: 'MinutesIndex' }">
				<template #icon>
					<NoteTextIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Documents -->
			<NcAppNavigationCaption :name="t('decidesk', 'Documents')" />
			<NcAppNavigationItem
				:name="t('decidesk', 'Documents')"
				:to="{ name: 'DigitalDocumentIndex' }">
				<template #icon>
					<FileDocumentIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('decidesk', 'Reports')"
				:to="{ name: 'ReportIndex' }">
				<template #icon>
					<ChartBoxIcon :size="20" />
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
import { NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem } from '@nextcloud/vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ChartBoxIcon from 'vue-material-design-icons/ChartBox.vue'
import CheckboxIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FormatListIcon from 'vue-material-design-icons/FormatListNumbered.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import NoteTextIcon from 'vue-material-design-icons/NoteTextOutline.vue'
import PencilBoxIcon from 'vue-material-design-icons/PencilBoxOutline.vue'
import TextBoxIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import VoteIcon from 'vue-material-design-icons/Vote.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		AccountGroupIcon,
		CalendarIcon,
		ChartBoxIcon,
		CheckboxIcon,
		CogIcon,
		DomainIcon,
		FileDocumentIcon,
		FormatListIcon,
		GavelIcon,
		HomeIcon,
		NoteTextIcon,
		PencilBoxIcon,
		TextBoxIcon,
		VoteIcon,
	},
}
</script>
