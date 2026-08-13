<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: minutes (notulen) authored for a Meeting.

 Posture: create + browse. The top-level Minutes index stays the
 canonical cross-meeting browse surface (refactor-decidesk-ia-alignment
 "split" placement); this tab is the per-meeting authoring affordance so
 a secretary never leaves the meeting context to start notulen. It lists
 minutes whose `meeting` link resolves to the current meeting, lets the
 user create a draft with the meeting reference pre-filled, and
 deep-links each row to MinutesDetail for full editing.

 @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#requirement-per-meeting-notulen-authoring-tab
-->
<template>
	<div
		class="decidesk-tab decidesk-tab--minutes"
		data-testid="meeting-minutes-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Minutes') }}
				<span v-if="!loading" class="decidesk-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="meeting-minutes-create"
				:aria-label="t('decidesk', 'Create minutes')"
				:disabled="creating"
				@click="createMinutes">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Create minutes') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load minutes')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No minutes yet for this meeting.')"
			:loading-text="t('decidesk', 'Loading minutes…')"
			@row-click="openDetail">
			<template #column-lifecycle="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="value"
					:color-map="lifecycleColors" />
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
	name: 'MeetingMinutesTab',
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
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-minutes-scoped-to-the-current-meeting */
		columns() {
			return [
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'lifecycle', label: this.t('decidesk', 'Status') },
				{ key: 'version', label: this.t('decidesk', 'Version') },
				{ key: 'approvedAt', label: this.t('decidesk', 'Approved') },
			]
		},
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-minutes-scoped-to-the-current-meeting */
		lifecycleColors() {
			return {
				draft: 'primary',
				review: 'warning',
				approved: 'success',
				published: 'success',
				rejected: 'error',
			}
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-minutes-scoped-to-the-current-meeting */
			handler() {
				this.refresh()
			},
		},
	},
	methods: {
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-minutes-scoped-to-the-current-meeting */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('minutes')
				const items = await store.fetchCollection('minutes', {
					meeting: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load minutes.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-creating-minutes-pre-fills-the-meeting-reference */
		async createMinutes() {
			if (!this.objectId || this.creating) return
			this.creating = true
			this.error = ''
			try {
				const store = ensureRelationType('minutes')
				const created = await store.saveObject('minutes', {
					title: this.t('decidesk', 'Minutes'),
					lifecycle: 'draft',
					version: 1,
					meeting: this.objectId,
				})
				const newId = created?.id || created?.uuid
				await this.refresh()
				if (newId) this.openDetail({ id: newId })
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Could not create minutes.')
			} finally {
				this.creating = false
			}
		},
		/**
		 * Navigate to the MinutesDetail page for a row.
		 *
		 * @param {object} row Minutes row (must carry `id` or `uuid`).
		 * @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-minutes-scoped-to-the-current-meeting
		 */
		openDetail(row) {
			const id = row && (row.id || row.uuid)
			if (!id) return
			this.$router.push({ name: 'MinutesDetail', params: { id } })
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
