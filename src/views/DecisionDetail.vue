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
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Publication action -->
			<CnDetailCard
				v-if="canPublish"
				:title="t('decidesk', 'ORI Publicatie')">
				<p class="decidesk-publish-hint">
					{{ t('decidesk', 'Dit besluit is aangenomen maar nog niet gepubliceerd via ORI.') }}
				</p>
				<NcButton
					:disabled="publishing"
					type="primary"
					@click="publishDecision">
					{{ publishing ? t('decidesk', 'Bezig met publiceren...') : t('decidesk', 'Publiceren') }}
				</NcButton>
			</CnDetailCard>

			<!-- Published status -->
			<CnDetailCard
				v-else-if="object.isPublished"
				:title="t('decidesk', 'ORI Publicatie')">
				<p class="decidesk-published-status">
					{{ t('decidesk', 'Gepubliceerd op') }}: {{ object.publishedAt }}
				</p>
			</CnDetailCard>

			<!-- Related ActionItems -->
			<CnDetailCard :title="t('decidesk', 'Actiepunten')">
				<p v-if="!actionItems.length" class="decidesk-empty">
					{{ t('decidesk', 'Geen actiepunten.') }}
				</p>
				<table v-else class="decidesk-action-items-table">
					<thead>
						<tr>
							<th>{{ t('decidesk', 'Titel') }}</th>
							<th>{{ t('decidesk', 'Verantwoordelijke') }}</th>
							<th>{{ t('decidesk', 'Deadline') }}</th>
							<th>{{ t('decidesk', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="item in actionItems"
							:key="item.id || item"
							class="decidesk-action-item-row"
							@click="goToActionItem(item)">
							<td>{{ item.title || item }}</td>
							<td>{{ item.assignee || '' }}</td>
							<td>{{ item.dueDate || '' }}</td>
							<td>{{ item.taskStatus || '' }}</td>
						</tr>
					</tbody>
				</table>
			</CnDetailCard>

			<!-- Related Motion -->
			<CnDetailCard v-if="relatedMotion" :title="t('decidesk', 'Gekoppelde motie')">
				<router-link :to="{ name: 'MotionDetail', params: { id: relatedMotion.id || relatedMotion } }">
					{{ relatedMotion.title || relatedMotion.name || relatedMotion.id || relatedMotion }}
				</router-link>
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

/**
 * Decision detail view with ORI publication action and related ActionItems table.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
export default {
	name: 'DecisionDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		NcButton,
	},
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
		/**
		 * Show "Publiceren" button only for adopted, unpublished decisions.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
		 */
		canPublish() {
			return this.object.outcome === 'adopted' && !this.object.isPublished
		},
		relatedMotion() {
			return this.object.relations?.motion
				?? this.object.motion
				?? null
		},
		actionItems() {
			return this.object.relations?.['action-item'] ?? []
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Titel'), value: this.object.title },
				{ label: this.t('decidesk', 'Besluitstekst'), value: this.object.text },
				{ label: this.t('decidesk', 'Besluitdatum'), value: this.object.decisionDate },
				{ label: this.t('decidesk', 'Uitkomst'), value: this.object.outcome },
				{ label: this.t('decidesk', 'Wettelijke grondslag'), value: this.object.legalBasis },
				{ label: this.t('decidesk', 'Gepubliceerd'), value: this.object.isPublished ? this.t('decidesk', 'Ja') : this.t('decidesk', 'Nee') },
				{ label: this.t('decidesk', 'Gepubliceerd op'), value: this.object.publishedAt },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('decision', this.id)
		},
		/**
		 * Set isPublished=true and publishedAt=now via ObjectService.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
		 */
		async publishDecision() {
			this.publishing = true
			try {
				const now = new Date().toISOString()
				await this.objectStore.saveObject('decision', this.id, {
					...this.object,
					isPublished: true,
					publishedAt: now,
				})
				await this.objectStore.fetchObject('decision', this.id)
			} catch (error) {
				console.error('Failed to publish decision:', error)
			} finally {
				this.publishing = false
			}
		},
		goToActionItem(item) {
			const id = item.id || item
			if (id) {
				this.$router.push({ name: 'ActionItemDetail', params: { id } })
			}
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-publish-hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 var(--default-grid-baseline);
}

.decidesk-published-status {
	color: var(--color-success);
	margin: 0;
}

.decidesk-action-items-table {
	width: 100%;
	border-collapse: collapse;
}

.decidesk-action-items-table th,
.decidesk-action-items-table td {
	padding: var(--default-grid-baseline);
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-action-items-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.decidesk-action-item-row {
	cursor: pointer;
}

.decidesk-action-item-row:hover {
	background: var(--color-background-hover);
}
</style>
