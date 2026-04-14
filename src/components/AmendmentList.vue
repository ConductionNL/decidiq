<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1
-->
<template>
	<div class="decidesk-amendment-list">
		<div class="decidesk-amendment-list__header">
			<h4>
				{{ t('decidesk', 'Amendments') }}
				<span v-if="amendments.length" class="decidesk-badge decidesk-badge--count" :aria-label="t('decidesk', '{n} amendments', { n: amendments.length })">
					{{ amendments.length }}
				</span>
			</h4>
			<NcButton
				v-if="canSubmit"
				type="secondary"
				:aria-label="t('decidesk', 'Submit Amendment')"
				@click="showCreateDialog = true">
				{{ t('decidesk', 'Submit Amendment') }}
			</NcButton>
		</div>

		<p v-if="!amendments.length" class="decidesk-empty">
			{{ t('decidesk', 'No amendments submitted yet.') }}
		</p>

		<ul v-else class="decidesk-amendment-list__items">
			<li
				v-for="amendment in amendments"
				:key="amendment.id"
				class="decidesk-amendment-list__item">
				<router-link :to="{ name: 'AmendmentDetail', params: { id: amendment.id } }">
					{{ amendment.title || amendment.id }}
				</router-link>
				<span class="decidesk-badge" :class="lifecycleBadgeClass(amendment.lifecycle)">
					{{ lifecycleLabel(amendment.lifecycle) }}
				</span>
				<span class="decidesk-amendment-list__proposer">
					{{ amendment.proposer }}
				</span>
			</li>
		</ul>

		<NcDialog
			v-if="showCreateDialog"
			:name="t('decidesk', 'Submit Amendment')"
			@closing="showCreateDialog = false">
			<CnSchemaFormDialog
				:schema="schema"
				:title="t('decidesk', 'Submit Amendment')"
				:object-store="objectStore"
				object-type="amendment"
				:initial-values="{ relations: { motion: [motionId] }, lifecycle: 'submitted', submittedAt: new Date().toISOString() }"
				@close="showCreateDialog = false"
				@saved="onAmendmentSaved" />
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { CnSchemaFormDialog } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1
 */
export default {
	name: 'AmendmentList',
	components: { NcButton, NcDialog, CnSchemaFormDialog },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		currentRole: { type: String, default: 'member' },
	},
	setup() {
		return { objectStore: useObjectStore() }
	},
	data() {
		return {
			amendments: [],
			showCreateDialog: false,
		}
	},
	computed: {
		canSubmit() {
			return ['member', 'chair', 'vice-chair', 'secretary'].includes(this.currentRole)
				&& ['submitted', 'debating'].includes(this.motionLifecycle)
		},
		schema() {
			return this.objectStore.getSchema('amendment')
		},
	},
	watch: {
		motionId: {
			immediate: true,
			handler() { this.loadAmendments() },
		},
	},
	methods: {
		async loadAmendments() {
			if (!this.motionId) return
			const allAmendments = this.objectStore.getObjects('amendment') ?? []
			this.amendments = allAmendments.filter((a) => {
				const relations = a.relations?.motion ?? []
				return relations.some((m) => (m.id || m) === this.motionId)
			})
		},
		onAmendmentSaved(amendment) {
			this.showCreateDialog = false
			this.amendments = [...this.amendments, amendment]
			this.$emit('amendment-created', amendment)
		},
		lifecycleLabel(lifecycle) {
			const labels = {
				submitted: this.t('decidesk', 'Ingediend'),
				debating: this.t('decidesk', 'Debat'),
				voting: this.t('decidesk', 'Stemronde'),
				adopted: this.t('decidesk', 'Aangenomen'),
				rejected: this.t('decidesk', 'Verworpen'),
			}
			return labels[lifecycle] || lifecycle
		},
		lifecycleBadgeClass(lifecycle) {
			return {
				'decidesk-badge--submitted': lifecycle === 'submitted',
				'decidesk-badge--debating': lifecycle === 'debating',
				'decidesk-badge--voting': lifecycle === 'voting',
				'decidesk-badge--adopted': lifecycle === 'adopted',
				'decidesk-badge--rejected': lifecycle === 'rejected',
			}
		},
	},
}
</script>

<style scoped>
.decidesk-amendment-list { margin-block: var(--default-grid-baseline); }
.decidesk-amendment-list__header { display: flex; align-items: center; justify-content: space-between; gap: var(--default-grid-baseline); }
.decidesk-amendment-list__items { list-style: none; padding: 0; margin: 0; }
.decidesk-amendment-list__item { display: flex; align-items: center; gap: var(--default-grid-baseline); padding: 4px 0; }
.decidesk-amendment-list__proposer { color: var(--color-text-maxcontrast); font-size: var(--font-size-small); }
.decidesk-empty { color: var(--color-text-maxcontrast); }
.decidesk-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: var(--border-radius-pill); font-size: var(--font-size-small); font-weight: var(--font-weight-bold); }
.decidesk-badge--count { background: var(--color-primary-light); color: var(--color-primary-text); }
.decidesk-badge--submitted { background: var(--color-background-hover); color: var(--color-text-maxcontrast); }
.decidesk-badge--debating { background: var(--color-warning-background); color: var(--color-warning); }
.decidesk-badge--voting { background: var(--color-primary-light); color: var(--color-primary-text); }
.decidesk-badge--adopted { background: var(--color-success-background); color: var(--color-success); }
.decidesk-badge--rejected { background: var(--color-error-background); color: var(--color-error); }
</style>
