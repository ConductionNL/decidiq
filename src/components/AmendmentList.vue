<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Amendments') + (amendments.length ? ` (${amendments.length})` : '')">
		<p v-if="!amendments.length" class="decidesk-empty">
			{{ t('decidesk', 'No amendments.') }}
		</p>
		<ul v-else class="decidesk-relations" aria-label="Amendments">
			<li v-for="amendment in amendments" :key="amendment.id">
				<router-link :to="{ name: 'AmendmentDetail', params: { id: amendment.id } }">
					{{ amendment.title || amendment.id }}
				</router-link>
				<span class="decidesk-badge decidesk-lifecycle">{{ amendment.lifecycle || '—' }}</span>
				<span class="decidesk-proposer">{{ amendment.proposer }}</span>
			</li>
		</ul>
		<NcButton
			v-if="canSubmitAmendment"
			type="secondary"
			:aria-label="t('decidesk', 'Submit amendment')"
			@click="showCreateDialog = true">
			{{ t('decidesk', 'Amendement indienen') }}
		</NcButton>

		<!-- Create amendment dialog -->
		<CnSchemaFormDialog
			v-if="showCreateDialog"
			:schema="objectStore.getSchema('amendment')"
			:title="t('decidesk', 'Submit Amendment')"
			:object-store="objectStore"
			object-type="amendment"
			:initial-data="{ motionId: motionId, lifecycle: 'submitted', status: 'submitted' }"
			@close="showCreateDialog = false"
			@saved="onAmendmentSaved" />
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnSchemaFormDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5
 */
export default {
	name: 'AmendmentList',
	components: { CnDetailCard, CnSchemaFormDialog, NcButton },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: 'submitted' },
	},
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},
	data() {
		return {
			showCreateDialog: false,
			amendments: [],
			loading: false,
		}
	},
	computed: {
		canSubmitAmendment() {
			return ['submitted', 'debating'].includes(this.motionLifecycle)
		},
	},
	watch: {
		motionId: {
			immediate: true,
			handler() {
				this.fetchAmendments()
			},
		},
	},
	methods: {
		async fetchAmendments() {
			if (!this.motionId) return
			this.loading = true
			try {
				const results = await this.objectStore.fetchObjects('amendment', { motionId: this.motionId })
				this.amendments = results ?? []
			} catch (e) {
				console.error('Failed to fetch amendments', e)
			} finally {
				this.loading = false
			}
		},
		onAmendmentSaved() {
			this.showCreateDialog = false
			this.fetchAmendments()
		},
	},
}
</script>

<style scoped>
.decidesk-relations {
	list-style: none;
	padding: 0;
	margin: 0 0 0.5rem;
}

.decidesk-relations li {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.25rem 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-badge {
	font-size: 0.75rem;
	padding: 0.1rem 0.4rem;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decidesk-proposer {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin-left: auto;
}

.decidesk-empty {
	color: var(--color-text-maxcontrast);
}
</style>
