<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: decisions (besluiten) taken in a Meeting.

 Posture: create + browse. The top-level Decisions index stays the
 canonical cross-meeting browse surface (refactor-decidesk-ia-alignment
 "split" placement); this tab is the per-meeting authoring affordance.
 Decisions are scoped to a meeting via their `meeting` link (the
 canonical per-meeting join used by the agenda/minutes tabs). It lists
 decisions for the current meeting, lets the user create one with the
 meeting reference pre-filled, and deep-links each row to
 DecisionDetail.

 @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#requirement-per-meeting-besluiten-authoring-tab
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--decisions"
		data-testid="meeting-decisions-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Decisions') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="meeting-decisions-create"
				:aria-label="t('decidiq', 'Create decision')"
				:disabled="creating"
				@click="createDecision">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Create decision') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load decisions')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No decisions yet for this meeting.')"
			:loadingText="t('decidiq', 'Loading decisions…')"
			@rowClick="openDetail">
			<template #column-outcome="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="value"
					:colorMap="outcomeColors" />
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingDecisionsTab',
	components: { CnDataTable, CnNoteCard, CnStatusBadge, NcButton, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			creating: false,
			error: '',
			rows: [],
		}
	},

	computed: {
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-decisions-scoped-to-the-current-meeting */
		columns() {
			return [
				{ key: 'title', label: this.t('decidiq', 'Title') },
				{ key: 'outcome', label: this.t('decidiq', 'Outcome') },
				{ key: 'decisionDate', label: this.t('decidiq', 'Decided') },
				{ key: 'isPublished', label: this.t('decidiq', 'Published') },
			]
		},

		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-decisions-scoped-to-the-current-meeting */
		outcomeColors() {
			return {
				adopted: 'success',
				rejected: 'error',
				deferred: 'warning',
				withdrawn: 'default',
			}
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-decisions-scoped-to-the-current-meeting */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-decisions-scoped-to-the-current-meeting */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('decision')
				const items = await store.fetchCollection('decision', {
					meeting: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load decisions.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-creating-a-decision-pre-fills-the-meeting-reference */
		async createDecision() {
			if (!this.objectId || this.creating) return
			this.creating = true
			this.error = ''
			try {
				const store = ensureRelationType('decision')
				const created = await store.saveObject('decision', {
					title: this.t('decidiq', 'Decision'),
					meeting: this.objectId,
				})
				const newId = created?.id || created?.uuid
				await this.refresh()
				if (newId) this.openDetail({ id: newId })
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Could not create decision.')
			} finally {
				this.creating = false
			}
		},

		/**
		 * Navigate to the DecisionDetail page for a row.
		 *
		 * @param {object} row Decision row (must carry `id` or `uuid`).
		 * @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-decisions-scoped-to-the-current-meeting
		 */
		openDetail(row) {
			const id = row && (row.id || row.uuid)
			if (!id) return
			this.$router.push({ name: 'DecisionDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
