<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Amendementen') + ` (${amendments.length})`">
		<p v-if="amendments.length === 0" class="decidesk-empty">
			{{ t('decidesk', 'Geen amendementen.') }}
		</p>
		<ul v-else class="decidesk-relations">
			<li v-for="amd in amendments" :key="amd.id">
				<router-link :to="{ name: 'AmendmentDetail', params: { id: amd.id } }">
					{{ amd.title || amd.id }}
				</router-link>
				<span class="decidesk-badge decidesk-badge--lifecycle">
					{{ amd.lifecycle }}
				</span>
				<span class="decidesk-muted">— {{ amd.proposer }}</span>
			</li>
		</ul>
		<NcButton
			v-if="canSubmitAmendment"
			type="secondary"
			:aria-label="t('decidesk', 'Submit amendment')"
			class="decidesk-add-amendment"
			@click="showCreateDialog = true">
			{{ t('decidesk', 'Amendement indienen') }}
		</NcButton>

		<CnSchemaFormDialog
			v-if="showCreateDialog"
			:schema="amendmentSchema"
			:title="t('decidesk', 'Amendement indienen')"
			:object-store="objectStore"
			object-type="amendment"
			@close="showCreateDialog = false"
			@saved="onAmendmentSaved" />
	</CnDetailCard>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailCard, CnSchemaFormDialog } from '@conduction/nextcloud-vue'

export default {
	name: 'AmendmentList',
	/**
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1
	 */
	components: { NcButton, CnDetailCard, CnSchemaFormDialog },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		objectStore: { type: Object, required: true },
	},
	data() {
		return {
			showCreateDialog: false,
		}
	},
	computed: {
		amendments() {
			const all = this.objectStore.getObjects('amendment') ?? []
			return all.filter(a => {
				const motionRel = (a.relations?.motion ?? [])
				return motionRel.some(r => (r.id ?? r) === this.motionId)
			})
		},
		amendmentSchema() {
			return this.objectStore.getSchema('amendment')
		},
		canSubmitAmendment() {
			return ['submitted', 'debating'].includes(this.motionLifecycle)
		},
	},
	mounted() {
		this.objectStore.fetchObjects('amendment')
	},
	methods: {
		onAmendmentSaved() {
			this.showCreateDialog = false
			this.objectStore.fetchObjects('amendment')
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
	margin: 0 0 var(--default-grid-baseline) 0;
	padding: 0;
}

.decidesk-relations li {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-relations li:last-child {
	border-bottom: none;
}

.decidesk-badge--lifecycle {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border-radius: var(--border-radius-pill);
	padding: 2px 8px;
	font-size: var(--font-size-small);
}

.decidesk-muted {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}

.decidesk-add-amendment {
	margin-top: var(--default-grid-baseline);
}
</style>
