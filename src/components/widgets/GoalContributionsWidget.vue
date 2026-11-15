<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 "What contributes to this goal" (board DcDoel, goals-linked-to-decisions
 REQ-011): every commitment, action item, planned agenda item and decision
 that names the goal, with Kind, What, Term and Status, the total in the
 heading and "View all n" when there are more than the first few. Each row
 links to the object's own page.

 A custom widget because one object list reads one schema, and this list
 joins four.

 @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-011-the-goal-page-lists-what-contributes-to-it
-->
<template>
	<section class="goal-contributions" data-testid="goal-contributions">
		<h3 class="goal-contributions__heading">
			{{ t('decidiq', 'What contributes to this goal') }}
			<span class="goal-contributions__total" data-testid="goal-contributions-total">{{ rows.length }}</span>
		</h3>
		<p v-if="loading" class="goal-contributions__muted">
			{{ t('decidiq', 'Loading …') }}
		</p>
		<p v-else-if="rows.length === 0" class="goal-contributions__muted" data-testid="goal-contributions-empty">
			{{ t('decidiq', 'Nothing is linked to this goal yet.') }}
		</p>
		<template v-else>
			<table class="goal-contributions__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Kind') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'What') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Term') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Status') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in shownRows" :key="row.id" :data-testid="'goal-contribution-' + row.kind">
						<td>{{ t('decidiq', row.kindLabel) }}</td>
						<td>
							<router-link v-if="row.location" class="goal-contributions__link" :to="row.location">
								{{ row.what || t('decidiq', '(untitled)') }}
							</router-link>
							<span v-else>{{ row.what }}</span>
						</td>
						<td>{{ shortDate(row.term) }}</td>
						<td>{{ row.status }}</td>
					</tr>
				</tbody>
			</table>
			<NcButton
				v-if="!showAll && rows.length > preview"
				variant="tertiary"
				data-testid="goal-contributions-all"
				@click="showAll = true">
				{{ t('decidiq', 'View all {count}', { count: rows.length }) }}
			</NcButton>
		</template>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { fetchContributions } from '../../services/goalContributions.js'
import { contributionRows, CONTRIBUTIONS_PREVIEW, objectIdOf, shortDate } from '../../utils/goalPage.js'

export default {
	name: 'GoalContributionsWidget',

	components: { NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			loading: false,
			byKind: {},
			showAll: false,
			preview: CONTRIBUTIONS_PREVIEW,
		}
	},

	computed: {
		goalId() {
			return String(this.objectId || objectIdOf(this.objectData) || '')
		},

		/**
		 * Every contribution, in kind order.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-011-the-goal-page-lists-what-contributes-to-it
		 */
		rows() {
			return contributionRows(this.byKind)
		},

		shownRows() {
			return this.showAll ? this.rows : this.rows.slice(0, this.preview)
		},
	},

	watch: {
		goalId: { immediate: true, handler() { this.load() } },
	},

	methods: {
		shortDate,

		/**
		 * Read the four kinds that can name the goal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-011-the-goal-page-lists-what-contributes-to-it
		 */
		async load() {
			this.showAll = false
			if (!this.goalId) {
				this.byKind = {}
				return
			}
			this.loading = true
			try {
				this.byKind = await fetchContributions(this.goalId)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.goal-contributions__heading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1em;
}

.goal-contributions__total {
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 0.9em;
}

.goal-contributions__muted {
	color: var(--color-text-maxcontrast);
}

.goal-contributions__table {
	width: 100%;
	border-collapse: collapse;
}

.goal-contributions__table th,
.goal-contributions__table td {
	padding: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.goal-contributions__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.goal-contributions__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
