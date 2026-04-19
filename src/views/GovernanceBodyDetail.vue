<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-6.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.name || t('decidesk', 'Governance Body')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Related Meetings')">
				<p v-if="!object.relations?.meeting?.length" class="decidesk-empty">
					{{ t('decidesk', 'No related meetings.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="meeting in object.relations.meeting" :key="meeting.id || meeting">
						<router-link :to="{ name: 'MeetingDetail', params: { id: meeting.id || meeting } }">
							{{ meeting.title || meeting.name || meeting.id || meeting }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
			<CnDetailCard :title="t('decidesk', 'Related Participants')">
				<p v-if="!object.relations?.participant?.length" class="decidesk-empty">
					{{ t('decidesk', 'No related participants.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="participant in object.relations.participant" :key="participant.id || participant">
						<router-link :to="{ name: 'ParticipantDetail', params: { id: participant.id || participant } }">
							{{ participant.displayName || participant.name || participant.id || participant }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar :object="object" :loading="loading" />
		</template>

		<template #edit-dialog>
			<CnSchemaFormDialog
				v-if="editing"
				:schema="schema"
				:object="object"
				:title="t('decidesk', 'Edit Governance Body')"
				:object-store="objectStore"
				object-type="governance-body"
				@close="editing = false"
				@saved="onEditSaved" />
		</template>

		<template #delete-dialog>
			<CnDeleteDialog
				v-if="showDeleteDialog"
				:object-name="object.name || ''"
				@confirm="confirmDelete"
				@close="showDeleteDialog = false" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'GovernanceBodyDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('governance-body', props.id, {
			objectStore,
			listRouteName: 'GovernanceBodies',
			detailRouteName: 'GovernanceBodyDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('governance-body')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Name'), value: this.object.name },
				{ label: this.t('decidesk', 'Type'), value: this.object.bodyType },
				{ label: this.t('decidesk', 'Domain'), value: this.object.domain },
				{ label: this.t('decidesk', 'Voting Default'), value: this.object.votingDefault },
				{ label: this.t('decidesk', 'Quorum Rule'), value: this.object.quorumRule },
				{ label: this.t('decidesk', 'Term Start'), value: this.object.termStart },
				{ label: this.t('decidesk', 'Term End'), value: this.object.termEnd },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('governance-body', this.id)
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-relations {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidesk-relations li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-relations li:last-child {
	border-bottom: none;
}
</style>
