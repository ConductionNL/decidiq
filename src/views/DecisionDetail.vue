<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
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
			<CnDetailCard v-if="canPublish" :title="t('decidesk', 'Acties')">
				<div class="decidesk-action-buttons">
					<NcButton
						type="primary"
						:disabled="publishing"
						@click="publishDecision">
						{{ t('decidesk', 'Publiceren') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<CnDetailCard v-if="object.text" :title="t('decidesk', 'Besluit')">
				<p>{{ object.text }}</p>
			</CnDetailCard>

			<CnDetailCard v-if="relatedActionItems.length" :title="t('decidesk', 'Gerelateerde actiepunten')">
				<ul class="decidesk-relations">
					<li v-for="ai in relatedActionItems" :key="ai.id">
						<router-link :to="{ name: 'ActionItemDetail', params: { id: ai.id } }">
							{{ ai.title || ai.id }}
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
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'DecisionDetail',
	components: { NcButton, CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog },
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
	data() {
		return {
			publishing: false,
		}
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
			return (this.objectStore.getObjects('action-item') ?? []).filter((ai) => {
				return (ai.relations ?? []).some(
					(r) => r.schema === 'decision' && (r.objectId || r.id) === decisionId
				)
			})
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Uitkomst'), value: this.outcomeLabel },
				{ label: this.t('decidesk', 'Besluitdatum'), value: this.formatDate(this.object.decisionDate) },
				{ label: this.t('decidesk', 'Juridische grondslag'), value: this.object.legalBasis },
				{ label: this.t('decidesk', 'Gepubliceerd'), value: this.object.isPublished ? this.t('decidesk', 'Ja') : this.t('decidesk', 'Nee') },
				{ label: this.t('decidesk', 'Gepubliceerd op'), value: this.formatDate(this.object.publishedAt) },
			].filter((item) => item.value)
		},
		outcomeLabel() {
			const labels = {
				adopted: this.t('decidesk', 'Aangenomen'),
				rejected: this.t('decidesk', 'Afgewezen'),
			}
			return labels[this.object.outcome] || this.object.outcome
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('decision', this.id)
		},
		async publishDecision() {
			this.publishing = true
			try {
				await this.objectStore.saveObject('decision', {
					...this.object,
					isPublished: true,
					publishedAt: new Date().toISOString(),
				})
				this.objectStore.fetchObject('decision', this.id)
			} finally {
				this.publishing = false
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-action-buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
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
