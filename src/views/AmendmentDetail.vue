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
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #header>
			<CnTimelineStages
				v-if="object.lifecycle"
				:stages="lifecycleStages"
				:current-stage="object.lifecycle"
				:aria-label="t('decidesk', 'Amendment lifecycle')" />
		</template>

		<template #properties>
			<!-- Conflict detection notice (task-5.3) -->
			<div v-if="conflictNote" class="decidesk-conflict-banner" role="alert">
				<span class="decidesk-conflict-icon" aria-hidden="true">⚠</span>
				{{ t('decidesk', 'Mogelijk conflict met ander amendement — raadpleeg de griffier') }}
			</div>

			<!-- Lifecycle action buttons -->
			<CnDetailCard :title="t('decidesk', 'Actions')">
				<div class="decidesk-motion-actions">
					<NcButton
						v-if="object.lifecycle === 'submitted'"
						type="secondary"
						:aria-label="t('decidesk', 'Open debate')"
						@click="transitionTo('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="object.lifecycle === 'debating'"
						type="primary"
						:aria-label="t('decidesk', 'Open voting round')"
						@click="transitionTo('voting')">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Parent motion link -->
			<CnDetailCard :title="t('decidesk', 'Bovenliggende motie')">
				<p v-if="!parentMotion" class="decidesk-empty">
					{{ t('decidesk', 'Geen motie gekoppeld.') }}
				</p>
				<router-link
					v-else
					:to="{ name: 'MotionDetail', params: { id: parentMotion.id || parentMotion } }">
					{{ parentMotion.title || parentMotion.id || parentMotion }}
				</router-link>
			</CnDetailCard>
		</template>

		<template #relations>
			<VotingRoundPanel
				:motion-id="id"
				:motion-lifecycle="object.lifecycle"
				:object-store="objectStore" />
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
import { NcButton } from '@nextcloud/vue'
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
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../store/store.js'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

export default {
	name: 'AmendmentDetail',
	/**
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.2
	 */
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
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
	computed: {
		schema() {
			return this.objectStore.getSchema('amendment')
		},
		lifecycleStages() {
			return [
				{ id: 'submitted', label: this.t('decidesk', 'Ingediend') },
				{ id: 'debating', label: this.t('decidesk', 'Debat') },
				{ id: 'voting', label: this.t('decidesk', 'Stemronde') },
				{ id: 'adopted', label: this.t('decidesk', 'Aangenomen'), type: 'success' },
				{ id: 'rejected', label: this.t('decidesk', 'Verworpen'), type: 'error' },
			]
		},
		conflictNote() {
			return (this.object.notes ?? []).find(n => (n.title ?? '').startsWith('Conflict:')) ?? null
		},
		parentMotion() {
			const motions = this.object.relations?.motion ?? []
			return motions.length > 0 ? motions[0] : null
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
		async transitionTo(newState) {
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				await fetch(`${appBaseUrl}/api/amendments/${this.id}/transition`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ newState }),
				})
				this.objectStore.fetchObject('amendment', this.id)
			} catch (e) {
				console.error('Transition failed', e)
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

.decidesk-motion-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}

.decidesk-conflict-banner {
	background: var(--color-warning);
	color: var(--color-warning-text);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline) * 2);
	margin-bottom: var(--default-grid-baseline);
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.decidesk-conflict-icon {
	font-size: 1.25em;
}
</style>
