<!-- TODO(decidesk-manifest-v1): obsolete after @conduction/nextcloud-vue release ships manifest-page-type-extensions + manifest-abstract-sidebar; delete in cleanup commit -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-8.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.displayName || t('decidesk', 'Participant')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Governance Body')">
				<p v-if="!object.relations?.['governance-body']?.length" class="decidesk-empty">
					{{ t('decidesk', 'No linked governance body.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="body in object.relations['governance-body']" :key="body.id || body">
						<router-link :to="{ name: 'GovernanceBodyDetail', params: { id: body.id || body } }">
							{{ body.name || body.displayName || body.id || body }}
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
				:title="t('decidesk', 'Edit Participant')"
				:object-store="objectStore"
				object-type="participant"
				@close="editing = false"
				@saved="onEditSaved" />
		</template>

		<template #delete-dialog>
			<CnDeleteDialog
				v-if="showDeleteDialog"
				:object-name="object.displayName || ''"
				@confirm="confirmDelete"
				@close="showDeleteDialog = false" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'ParticipantDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('participant', props.id, {
			objectStore,
			listRouteName: 'Participants',
			detailRouteName: 'ParticipantDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('participant')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Name'), value: this.object.displayName },
				{ label: this.t('decidesk', 'Role'), value: this.object.role },
				{ label: this.t('decidesk', 'Party'), value: this.object.party },
				{ label: this.t('decidesk', 'Email'), value: this.object.email },
				{ label: this.t('decidesk', 'Joined'), value: this.object.joinedAt },
				{ label: this.t('decidesk', 'Left'), value: this.object.leftAt },
				{ label: this.t('decidesk', 'Voting Weight'), value: this.object.votingWeight },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('participant', this.id)
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
