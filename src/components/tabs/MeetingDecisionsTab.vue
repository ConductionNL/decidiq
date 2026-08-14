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
		class="decidesk-tab decidesk-tab--decisions"
		data-testid="meeting-decisions-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Decisions') }}
				<span v-if="!loading" class="decidesk-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="meeting-decisions-create"
				:aria-label="t('decidesk', 'Create decision')"
				:disabled="creating"
				@click="createDecision">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Create decision') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load decisions')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidesk', 'No decisions yet for this meeting.')"
			:loadingText="t('decidesk', 'Loading decisions…')"
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
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'outcome', label: this.t('decidesk', 'Outcome') },
				{ key: 'decisionDate', label: this.t('decidesk', 'Decided') },
				{ key: 'isPublished', label: this.t('decidesk', 'Published') },
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
					e?.message || this.t('decidesk', 'Failed to load decisions.')
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
					title: this.t('decidesk', 'Decision'),
					meeting: this.objectId,
				})
				const newId = created?.id || created?.uuid
				await this.refresh()
				if (newId) this.openDetail({ id: newId })
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Could not create decision.')
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
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
