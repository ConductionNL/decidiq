<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AmendmentList component — embedded in MotionDetail, lists amendments for a motion.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Amendementen') + (amendments.length ? ` (${amendments.length})` : '')">
		<p v-if="loading" class="decidesk-empty">
			{{ t('decidesk', 'Laden…') }}
		</p>
		<p v-else-if="!amendments.length" class="decidesk-empty">
			{{ t('decidesk', 'Geen amendementen.') }}
		</p>
		<ul v-else class="decidesk-amendment-list" role="list">
			<li v-for="amendment in amendments" :key="amendment.id || amendment.uuid" class="decidesk-amendment-item">
				<router-link :to="{ name: 'AmendmentDetail', params: { id: amendment.id || amendment.uuid } }">
					<span class="decidesk-amendment-title">{{ amendment.title }}</span>
				</router-link>
				<span class="decidesk-amendment-meta">
					{{ amendment.proposer }} &middot;
					<CnStatusBadge :status="amendment.lifecycle" />
				</span>
			</li>
		</ul>
		<NcButton
			v-if="canSubmitAmendment"
			type="secondary"
			@click="goNewAmendment">
			{{ t('decidesk', 'Amendement indienen') }}
		</NcButton>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'AmendmentList',
	components: { CnDetailCard, CnStatusBadge, NcButton },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
	},
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},
	data() {
		return {
			amendments: [],
			loading: false,
		}
	},
	computed: {
		canSubmitAmendment() {
			return ['submitted', 'debating'].includes(this.motionLifecycle)
		},
	},
	async mounted() {
		await this.fetchAmendments()
	},
	methods: {
		async fetchAmendments() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchObjects('amendment', {
					'relations.motion': this.motionId,
				})
				this.amendments = result?.results || []
			} catch (e) {
				this.amendments = []
			} finally {
				this.loading = false
			}
		},
		goNewAmendment() {
			this.$router.push({ name: 'AmendmentDetail', params: { id: 'new' } })
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
	margin: 0 0 var(--default-grid-baseline);
	padding: 0;
}

.decidesk-amendment-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-amendment-item:last-child {
	border-bottom: none;
}

.decidesk-amendment-title {
	font-weight: bold;
}

.decidesk-amendment-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.875em;
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) / 2);
}
</style>
