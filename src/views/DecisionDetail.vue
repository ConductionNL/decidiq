<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Besluit')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<!-- Publish action -->
				<div v-if="canPublish" class="decidesk-publish-action">
					<NcButton type="primary" @click="publish">
						{{ t('decidesk', 'Publiceren') }}
					</NcButton>
				</div>
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Besluit')">
				<p>{{ object.text }}</p>
			</CnDetailCard>

			<CnDetailCard
				v-if="relatedActionItems.length"
				:title="t('decidesk', 'Gerelateerde actiepunten')">
				<ul class="decidesk-relations">
					<li v-for="ai in relatedActionItems" :key="ai.id">
						<router-link :to="{ name: 'ActionItemDetail', params: { id: ai.id } }">
							{{ ai.title }} — {{ ai.assignee || '—' }}
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
				:title="t('decidesk', 'Besluit bewerken')"
				:object-store="objectStore"
				object-type="decision"
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
import { useDecisionStore } from '../store/modules/decisions.js'

export default {
	name: 'DecisionDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, NcButton },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('decision', props.id, {
			objectStore,
			listRouteName: 'Decisions',
			detailRouteName: 'DecisionDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('decision')
		},
		canPublish() {
			return this.object.outcome === 'adopted' && !this.object.isPublished
		},
		relatedActionItems() {
			const decisionId = this.object.id
			if (!decisionId) return []
			return (this.objectStore.getObjects('action-item') ?? []).filter((ai) =>
				(ai.relations || []).some(
					(r) => r.schema === 'decision' && (r.objectId || r.id) === decisionId,
				),
			)
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Uitkomst'), value: this.object.outcome === 'adopted' ? this.t('decidesk', 'Aangenomen') : this.t('decidesk', 'Afgewezen') },
				{ label: this.t('decidesk', 'Besluitdatum'), value: this.formatDate(this.object.decisionDate) },
				{ label: this.t('decidesk', 'Juridische grondslag'), value: this.object.legalBasis },
				{ label: this.t('decidesk', 'Publicatiestatus'), value: this.object.isPublished ? this.t('decidesk', 'Gepubliceerd op') + ' ' + this.formatDate(this.object.publishedAt) : this.t('decidesk', 'Niet gepubliceerd') },
			].filter((item) => item.value)
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('decision', this.id)
		},
		async publish() {
			await useDecisionStore().publishDecision(this.id)
			await this.objectStore.fetchObject('decision', this.id)
		},
		formatDate(dateStr) {
			if (!dateStr) return null
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-publish-action {
	margin-bottom: 16px;
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
