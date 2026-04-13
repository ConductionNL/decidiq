<template>
	<CnDetailPage
		v-bind="detailView"
		:title="detailView.object.value?.name || t('decidesk', 'Governance Body')"
		object-type="governanceBody"
		@edit="detailView.editing.value = true"
		@delete="detailView.showDeleteDialog.value = true">
		<template #content>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<template #content>
					<dl class="decidesk-detail__properties">
						<dt>{{ t('decidesk', 'Name') }}</dt>
						<dd>{{ object.name }}</dd>
						<dt>{{ t('decidesk', 'Body Type') }}</dt>
						<dd>{{ object.bodyType }}</dd>
						<dt>{{ t('decidesk', 'Domain') }}</dt>
						<dd>{{ object.domain }}</dd>
						<dt>{{ t('decidesk', 'Voting Default') }}</dt>
						<dd>{{ object.votingDefault }}</dd>
						<dt>{{ t('decidesk', 'Quorum Rule') }}</dt>
						<dd>{{ object.quorumRule }}</dd>
						<dt>{{ t('decidesk', 'Term Start') }}</dt>
						<dd>{{ object.termStart }}</dd>
						<dt>{{ t('decidesk', 'Term End') }}</dt>
						<dd>{{ object.termEnd }}</dd>
					</dl>
				</template>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Meetings')">
				<template #content>
					<ul v-if="relatedMeetings.length" class="decidesk-detail__related-list">
						<li
							v-for="item in relatedMeetings"
							:key="item.id"
							class="decidesk-detail__related-item"
							@click="$router.push({ name: 'MeetingDetail', params: { id: item.id } })">
							<span class="decidesk-detail__related-title">{{ item.title || item.id }}</span>
							<span class="decidesk-detail__related-meta">{{ item.lifecycle }}</span>
						</li>
					</ul>
					<p v-else class="decidesk-detail__empty">
						{{ t('decidesk', 'No meetings linked to this governance body.') }}
					</p>
				</template>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Participants')">
				<template #content>
					<ul v-if="relatedParticipants.length" class="decidesk-detail__related-list">
						<li
							v-for="item in relatedParticipants"
							:key="item.id"
							class="decidesk-detail__related-item"
							@click="$router.push({ name: 'ParticipantDetail', params: { id: item.id } })">
							<span class="decidesk-detail__related-title">{{ item.displayName || item.id }}</span>
							<span v-if="item.role" class="decidesk-detail__related-meta">{{ item.role }}</span>
						</li>
					</ul>
					<p v-else class="decidesk-detail__empty">
						{{ t('decidesk', 'No participants linked to this governance body.') }}
					</p>
				</template>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="object.id"
				:object-type="'governanceBody'"
				:object-id="object.id"
				:object-store="governanceBodyStore" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnObjectSidebar, useDetailView } from '@conduction/nextcloud-vue'
import { useGovernanceBodyStore } from '../store/modules/governanceBody.js'
import { useMeetingStore } from '../store/modules/meeting.js'
import { useParticipantStore } from '../store/modules/participant.js'

export default {
	name: 'GovernanceBodyDetail',
	components: { CnDetailPage, CnDetailCard, CnObjectSidebar },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const governanceBodyStore = useGovernanceBodyStore()
		const meetingStore = useMeetingStore()
		const participantStore = useParticipantStore()
		const detailView = useDetailView('governanceBody', props.id, {
			objectStore: () => governanceBodyStore,
			listRouteName: 'GovernanceBodies',
			detailRouteName: 'GovernanceBodyDetail',
		})
		return { detailView, governanceBodyStore, meetingStore, participantStore }
	},
	data() {
		return {
			relatedMeetings: [],
			relatedParticipants: [],
		}
	},
	computed: {
		object() {
			return this.detailView.object.value || {}
		},
	},
	watch: {
		'object.id': {
			immediate: true,
			async handler(id) {
				if (!id) return
				const [meetings, participants] = await Promise.all([
					this.governanceBodyStore.fetchUsed('governanceBody', id, { _schema: 'meeting' }),
					this.governanceBodyStore.fetchUsed('governanceBody', id, { _schema: 'participant' }),
				])
				this.relatedMeetings = meetings || []
				this.relatedParticipants = participants || []
			},
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

.decidesk-detail__related-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.decidesk-detail__related-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.decidesk-detail__related-item:last-child {
	border-bottom: none;
}

.decidesk-detail__related-item:hover {
	color: var(--color-primary-element);
}

.decidesk-detail__related-meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.decidesk-detail__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
