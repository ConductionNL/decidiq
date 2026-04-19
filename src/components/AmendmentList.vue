<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Amendments') + (amendments.length ? ` (${amendments.length})` : '')">
		<p v-if="!amendments.length" class="decidesk-empty">
			{{ t('decidesk', 'No amendments for this motion.') }}
		</p>
		<ul v-else class="decidesk-amendment-list">
			<li v-for="amendment in amendments" :key="amendment.id || amendment.uuid">
				<router-link :to="{ name: 'AmendmentDetail', params: { id: amendment.id || amendment.uuid } }">
					<strong>{{ amendment.title }}</strong>
				</router-link>
				<span class="decidesk-proposer">{{ amendment.proposer }}</span>
				<CnStatusBadge :status="amendment.lifecycle" />
			</li>
		</ul>
		<div
			v-if="canSubmitAmendment"
			class="decidesk-actions">
			<NcButton
				type="secondary"
				@click="showCreateDialog = true">
				{{ t('decidesk', 'Submit Amendment') }}
			</NcButton>
		</div>

		<CnSchemaFormDialog
			v-if="showCreateDialog"
			:schema="schema"
			:object="{ relations: { motion: [motionId] } }"
			:title="t('decidesk', 'Submit Amendment')"
			:object-store="objectStore"
			object-type="amendment"
			@close="showCreateDialog = false"
			@saved="onAmendmentSaved" />
	</CnDetailCard>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailCard, CnStatusBadge, CnSchemaFormDialog } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'AmendmentList',
	components: { NcButton, CnDetailCard, CnStatusBadge, CnSchemaFormDialog },
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
			amendments: [],
			showCreateDialog: false,
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('amendment')
		},
		canSubmitAmendment() {
			return ['submitted', 'debating'].includes(this.motionLifecycle)
		},
	},
	watch: {
		motionId() {
			this.loadAmendments()
		},
	},
	mounted() {
		this.loadAmendments()
	},
	methods: {
		async loadAmendments() {
			try {
				this.amendments = (await this.objectStore.fetchObjects('amendment', { 'relations.motion': this.motionId }))?.results ?? []
			} catch { /* ignore */ }
		},
		onAmendmentSaved() {
			this.showCreateDialog = false
			this.loadAmendments()
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-amendment-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidesk-amendment-list li {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-amendment-list li:last-child {
	border-bottom: none;
}

.decidesk-proposer {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	flex: 1;
}

.decidesk-actions {
	margin-top: var(--default-grid-baseline);
}
</style>
