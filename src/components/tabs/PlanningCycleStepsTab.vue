<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The Steps widget on a planning cycle's page (planning-cycle-generate-from-
 template, pla-12): the steps in order with delivery deadline, committee
 date, status and an Overdue badge. Rows come from
 src/utils/planningCycleSteps.js; a row opens the step's own page.

 @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
-->
<template>
	<div class="cycle-steps" data-testid="planning-cycle-steps-tab">
		<p v-if="loading" class="cycle-steps__muted">
			{{ t('decidiq', 'Loading the steps…') }}
		</p>
		<p v-else-if="error" class="cycle-steps__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="!rows.length" class="cycle-steps__muted">
			{{ t('decidiq', 'This cycle has no steps yet.') }}
		</p>
		<table
			v-else
			class="cycle-steps__table"
			data-testid="planning-cycle-steps-table">
			<caption class="hidden-visually">
				{{
					t('decidiq', 'Steps of this cycle in order')
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">#</th>
					<th scope="col">
						{{ t('decidiq', 'Step') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Delivery deadline') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Committee date') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Status') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.id || row.sequence"
					data-testid="planning-cycle-step-row">
					<td>{{ row.sequence }}</td>
					<th scope="row">
						<router-link
							v-if="row.id"
							:to="{
								name: 'PlanningCycleStepDetail',
								params: { id: row.id },
							}">
							{{ row.label }}
						</router-link>
						<template v-else>
							{{ row.label }}
						</template>
					</th>
					<td>
						{{ formatDate(row.deliveryDeadline) }}
						<span
							v-if="row.overdue"
							class="cycle-steps__overdue"
							data-testid="planning-cycle-step-overdue">
							{{ t('decidiq', 'Overdue') }}
						</span>
					</td>
					<td>{{ formatDate(row.committeeDate) }}</td>
					<td>{{ statusLabel(row.status) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { stepRows } from '../../utils/planningCycleSteps.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'PlanningCycleStepsTab',

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return { loading: false, error: '', steps: [] }
	},

	computed: {
		/** @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps */
		rows() {
			return stepRows(this.steps, new Date())
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load the cycle's steps.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				this.steps =
					(await ensureRelationType('planning-cycle-step').fetchCollection(
						'planning-cycle-step',
						{ cycle: String(this.objectId), _limit: 100 },
					)) || []
			} catch {
				this.error = this.t('decidiq', 'Could not load the steps.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} status A step status.
		 * @return {string} Its label.
		 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
		 */
		statusLabel(status) {
			return (
				{
					planned: this.t('decidiq', 'Planned'),
					'documents-received': this.t('decidiq', 'Documents received'),
					'in-progress': this.t('decidiq', 'In progress'),
					adopted: this.t('decidiq', 'Adopted'),
					completed: this.t('decidiq', 'Completed'),
				}[status] || status
			)
		},

		/**
		 * @param {string} value A date (YYYY-MM-DD).
		 * @return {string} The local date, or ''.
		 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
		 */
		formatDate(value) {
			if (!value) return ''
			const [y, m, d] = value.split('-').map(Number)
			const date = new Date(y, (m || 1) - 1, d || 1)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.cycle-steps__table {
	width: 100%;
	border-collapse: collapse;
}

.cycle-steps__table th,
.cycle-steps__table td {
	padding: 6px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.cycle-steps__overdue {
	margin-inline-start: 6px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-error);
	color: var(--color-primary-element-text, #fff);
	font-size: 0.85em;
}

.cycle-steps__muted {
	color: var(--color-text-maxcontrast);
}

.cycle-steps__error {
	color: var(--color-error);
}
</style>
