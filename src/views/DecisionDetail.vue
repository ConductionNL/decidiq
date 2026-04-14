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
				<div v-if="canPublish" class="decidesk-publish-action">
					<NcButton
						type="primary"
						:disabled="publishing"
						@click="publish">
						{{ t('decidesk', 'Publiceren') }}
					</NcButton>
				</div>
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Gerelateerde motie')">
				<p v-if="!object.relations?.motion" class="decidesk-empty">
					{{ t('decidesk', 'Geen gerelateerde motie.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li>
						<router-link :to="{ name: 'MotionDetail', params: { id: object.relations.motion.id || object.relations.motion } }">
							{{ object.relations.motion.title || object.relations.motion.id || object.relations.motion }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
			<CnDetailCard :title="t('decidesk', 'Gerelateerde actiepunten')">
				<p v-if="!object.relations?.['action-item']?.length" class="decidesk-empty">
					{{ t('decidesk', 'Geen gerelateerde actiepunten.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="ai in object.relations['action-item']" :key="ai.id || ai">
						<router-link :to="{ name: 'ActionItemDetail', params: { id: ai.id || ai } }">
							{{ ai.title || ai.id || ai }}
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
import { useDecisionStore } from '../store/modules/decisions.js'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'DecisionDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, NcButton },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const decisionStore = useDecisionStore()
		const detailView = useDetailView('decision', props.id, {
			objectStore,
			listRouteName: 'Decisions',
			detailRouteName: 'DecisionDetail',
		})
		return { ...detailView, objectStore, decisionStore }
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
			return this.object?.outcome === 'adopted' && !this.object?.isPublished
		},
		propertyItems() {
			const outcomeLabel = this.object.outcome === 'adopted'
				? this.t('decidesk', 'Aangenomen')
				: this.object.outcome === 'rejected'
					? this.t('decidesk', 'Afgewezen')
					: this.object.outcome
			const publishedLabel = this.object.isPublished
				? this.t('decidesk', 'Ja') + (this.object.publishedAt ? ' (' + this.formatDate(this.object.publishedAt) + ')' : '')
				: this.t('decidesk', 'Nee')
			return [
				{ label: this.t('decidesk', 'Besluit'), value: this.object.text },
				{ label: this.t('decidesk', 'Uitkomst'), value: outcomeLabel },
				{ label: this.t('decidesk', 'Besluitdatum'), value: this.formatDate(this.object.decisionDate) },
				{ label: this.t('decidesk', 'Juridische grondslag'), value: this.object.legalBasis },
				{ label: this.t('decidesk', 'Gepubliceerd'), value: publishedLabel },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('decision', this.id)
		},
		async publish() {
			this.publishing = true
			try {
				await this.decisionStore.publishDecision(this.id)
				await this.objectStore.fetchObject('decision', this.id)
			} finally {
				this.publishing = false
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
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

.decidesk-publish-action {
	margin-bottom: 12px;
}
</style>
