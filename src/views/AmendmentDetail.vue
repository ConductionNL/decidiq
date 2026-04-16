<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Amendment')"
		:sidebar="true"
		:object-type="'amendment'"
		:object-id="id"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<!-- Conflict warning banner -->
			<div v-if="hasConflict" class="decidesk-conflict-banner" role="alert">
				<span class="decidesk-conflict-icon" aria-hidden="true">⚠</span>
				{{ t('decidesk', 'Possible conflict with another amendment — consult the clerk') }}
			</div>

			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Lifecycle actions for chair/secretary -->
			<CnDetailCard :title="t('decidesk', 'Actions')">
				<div class="decidesk-actions">
					<NcButton
						v-if="canTransitionTo('debating')"
						type="secondary"
						:disabled="transitioning"
						@click="transition('debating')">
						{{ t('decidesk', 'Open Debate') }}
					</NcButton>
					<NcButton
						v-if="canTransitionTo('voting')"
						type="secondary"
						:disabled="transitioning"
						@click="transition('voting')">
						{{ t('decidesk', 'Open Voting Round') }}
					</NcButton>
				</div>
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Parent Motion')">
				<p v-if="!object.relations?.motion?.length" class="decidesk-empty">
					{{ t('decidesk', 'No parent motion linked.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="motion in object.relations.motion" :key="motion.id || motion">
						<router-link :to="{ name: 'MotionDetail', params: { id: motion.id || motion } }">
							{{ motion.title || motion.name || motion.id || motion }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>

			<!-- Voting round panel for this amendment -->
			<VotingRoundPanel
				:motion-id="id"
				:motion-schema="'amendment'"
				:motion-lifecycle="object.lifecycle" />
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
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

export default {
	name: 'AmendmentDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnSchemaFormDialog,
		CnDeleteDialog,
		VotingRoundPanel,
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
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('amendment')
		},
		hasConflict() {
			const notes = this.object.notes ?? []
			return notes.some(n => String(n.title ?? '').startsWith('Conflict:'))
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Proposer'), value: this.object.proposer },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Submitted At'), value: this.object.submittedAt },
				{ label: this.t('decidesk', 'Text'), value: this.object.text },
			]
		},
	},
	methods: {
		canTransitionTo(state) {
			const allowed = {
				submitted: ['debating'],
				debating: ['voting'],
			}
			return (allowed[this.object.lifecycle] ?? []).includes(state)
		},
		async transition(newState) {
			this.transitioning = true
			try {
				await fetch(OC.generateUrl(`/apps/decidesk/api/amendments/${this.id}/transition`), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ newState }),
				})
				await this.objectStore.fetchObject('amendment', this.id)
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

.decidesk-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

.decidesk-conflict-banner {
	background-color: var(--color-warning-background);
	color: var(--color-warning-text);
	border: 1px solid var(--color-warning-border);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	margin-bottom: var(--default-grid-baseline);
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.decidesk-conflict-icon {
	font-size: 1.2em;
}
</style>
