<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Actiepunt')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<div class="decidesk-item-actions">
					<NcButton
						v-if="object.taskStatus === 'open'"
						type="secondary"
						@click="startItem">
						{{ t('decidesk', 'In behandeling nemen') }}
					</NcButton>
					<NcButton
						v-if="object.taskStatus === 'in-progress'"
						type="primary"
						@click="completeItem">
						{{ t('decidesk', 'Afronden') }}
					</NcButton>
				</div>
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Gerelateerd besluit')">
				<p v-if="!object.relations?.decision" class="decidesk-empty">
					{{ t('decidesk', 'Geen gerelateerd besluit.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li>
						<router-link :to="{ name: 'DecisionDetail', params: { id: object.relations.decision.id || object.relations.decision } }">
							{{ object.relations.decision.title || object.relations.decision.id || object.relations.decision }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
			<CnDetailCard :title="t('decidesk', 'Gerelateerde vergadering')">
				<p v-if="!object.relations?.meeting" class="decidesk-empty">
					{{ t('decidesk', 'Geen gerelateerde vergadering.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li>
						<router-link :to="{ name: 'MeetingDetail', params: { id: object.relations.meeting.id || object.relations.meeting } }">
							{{ object.relations.meeting.title || object.relations.meeting.id || object.relations.meeting }}
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
				:title="t('decidesk', 'Actiepunt bewerken')"
				:object-store="objectStore"
				object-type="action-item"
				@close="editing = false"
				@saved="onEditSaved" />
		</template>

		<template #delete-dialog>
			<CnDeleteDialog
				v-if="showDeleteDialog"
				:object-name="object.title || ''"
				@confirm="confirmDelete"
				@close="showDeleteDialog = false" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'ActionItemDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, NcButton },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('action-item', props.id, {
			objectStore,
			listRouteName: 'ActionItems',
			detailRouteName: 'ActionItemDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('action-item')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Omschrijving'), value: this.object.description },
				{ label: this.t('decidesk', 'Status'), value: this.object.taskStatus },
				{ label: this.t('decidesk', 'Verantwoordelijke'), value: this.object.assignee },
				{ label: this.t('decidesk', 'Deadline'), value: this.formatDate(this.object.dueDate) },
				{ label: this.t('decidesk', 'Afgerond op'), value: this.formatDate(this.object.completedAt) },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('action-item', this.id)
		},
		async startItem() {
			await this.objectStore.saveObject('action-item', {
				...this.object,
				taskStatus: 'in-progress',
			})
		},
		async completeItem() {
			await this.objectStore.saveObject('action-item', {
				...this.object,
				taskStatus: 'completed',
				completedAt: new Date().toISOString(),
			})
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-item-actions {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

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
