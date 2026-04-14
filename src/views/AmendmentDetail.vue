<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Amendment detail view — shows amendment text, lifecycle timeline, conflict warnings.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Amendment')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<!-- Conflict detection notice -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.3 -->
			<div v-if="hasConflict"
				class="decidesk-conflict-warning"
				role="alert"
				aria-live="assertive">
				<strong>{{ t('decidesk', 'Mogelijk conflict') }}</strong>
				{{ t('decidesk', 'Mogelijk conflict met ander amendement — raadpleeg de griffier') }}
			</div>

			<!-- Lifecycle timeline -->
			<CnDetailCard :title="t('decidesk', 'Lifecycle')">
				<CnTimelineStages :stages="lifecycleStages" :current="object.lifecycle" />
				<div class="decidesk-lifecycle-actions">
					<NcButton
						v-if="canTransitionTo('debating')"
						type="primary"
						:disabled="transitioning"
						@click="transition('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="canTransitionTo('voting')"
						type="primary"
						:disabled="transitioning"
						@click="transition('voting')">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
				</div>
				<p v-if="transitionError" class="decidesk-error">
					{{ transitionError }}
				</p>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Parent Motion')">
				<p v-if="!object.relations?.motion?.length" class="decidesk-empty">
					{{ t('decidesk', 'No linked motion.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="motion in object.relations.motion" :key="motion.id || motion">
						<router-link :to="{ name: 'MotionDetail', params: { id: motion.id || motion } }">
							{{ motion.title || motion.id || motion }}
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
				:title="t('decidesk', 'Edit Amendment')"
				:object-store="objectStore"
				object-type="amendment"
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
import {
	CnDetailPage,
	CnDetailCard,
	CnDetailGrid,
	CnObjectSidebar,
	CnSchemaFormDialog,
	CnDeleteDialog,
	CnTimelineStages,
	useDetailView,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'AmendmentDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
		NcButton,
	},
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('amendment', props.id, {
			objectStore,
			listRouteName: 'Motions',
			detailRouteName: 'AmendmentDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			transitioning: false,
			transitionError: null,
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('amendment')
		},
		lifecycleStages() {
			return [
				{ key: 'submitted', label: this.t('decidesk', 'Ingediend') },
				{ key: 'debating', label: this.t('decidesk', 'Debat') },
				{ key: 'voting', label: this.t('decidesk', 'Stemronde') },
				{ key: 'adopted', label: this.t('decidesk', 'Aangenomen'), type: 'success' },
				{ key: 'rejected', label: this.t('decidesk', 'Verworpen'), type: 'error' },
			]
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Proposer'), value: this.object.proposer },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Submitted'), value: this.object.submittedAt },
				{ label: this.t('decidesk', 'Amendment text'), value: this.object.text },
			]
		},
		hasConflict() {
			if (!this.object.notes) return false
			return this.object.notes.some(n => n.title && n.title.startsWith('Conflict:'))
		},
	},
	methods: {
		canTransitionTo(state) {
			const allowedFrom = {
				debating: ['submitted'],
				voting: ['debating'],
			}
			return (allowedFrom[state] || []).includes(this.object.lifecycle)
		},
		async transition(newState) {
			this.transitioning = true
			this.transitionError = null
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/decidesk/api/amendments/${this.id}/transition`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({ newState }),
					},
				)
				if (!response.ok) {
					const data = await response.json()
					this.transitionError = data.message || this.t('decidesk', 'Transitie mislukt')
				} else {
					await this.objectStore.fetchObject('amendment', this.id)
				}
			} catch (e) {
				this.transitionError = this.t('decidesk', 'Transitie mislukt')
			} finally {
				this.transitioning = false
			}
		},
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('amendment', this.id)
		},
	},
}
</script>

<style scoped>
.decidesk-conflict-warning {
	background-color: var(--color-warning-background);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	margin-bottom: var(--default-grid-baseline);
	color: var(--color-text-light);
}

.decidesk-lifecycle-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin-top: var(--default-grid-baseline);
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

.decidesk-error {
	color: var(--color-error);
	margin: var(--default-grid-baseline) 0 0;
}
</style>
