<template>
	<CnDetailPage
		v-bind="detailView"
		:title="detailView.object.value?.displayName || t('decidesk', 'Participant')"
		object-type="participant"
		@edit="detailView.editing.value = true"
		@delete="detailView.showDeleteDialog.value = true">
		<template #content>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<template #content>
					<dl class="decidesk-detail__properties">
						<dt>{{ t('decidesk', 'Display Name') }}</dt>
						<dd>{{ object.displayName }}</dd>
						<dt>{{ t('decidesk', 'Role') }}</dt>
						<dd>{{ object.role }}</dd>
						<dt>{{ t('decidesk', 'Party') }}</dt>
						<dd>{{ object.party }}</dd>
						<dt>{{ t('decidesk', 'Email') }}</dt>
						<dd>{{ object.email }}</dd>
						<dt>{{ t('decidesk', 'Joined At') }}</dt>
						<dd>{{ object.joinedAt }}</dd>
						<dt>{{ t('decidesk', 'Left At') }}</dt>
						<dd>{{ object.leftAt }}</dd>
						<dt>{{ t('decidesk', 'Voting Weight') }}</dt>
						<dd>{{ object.votingWeight }}</dd>
					</dl>
				</template>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="object.id"
				:object-type="'participant'"
				:object-id="object.id"
				:object-store="participantStore" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnObjectSidebar, useDetailView } from '@conduction/nextcloud-vue'
import { useParticipantStore } from '../store/modules/participant.js'

export default {
	name: 'ParticipantDetail',
	components: { CnDetailPage, CnDetailCard, CnObjectSidebar },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const participantStore = useParticipantStore()
		const detailView = useDetailView('participant', props.id, {
			objectStore: () => participantStore,
			listRouteName: 'Participants',
			detailRouteName: 'ParticipantDetail',
		})
		return { detailView, participantStore }
	},
	computed: {
		object() {
			return this.detailView.object.value || {}
		},
	},
}
</script>

<style scoped>
.decidesk-detail__properties {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 8px 16px;
}

.decidesk-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
