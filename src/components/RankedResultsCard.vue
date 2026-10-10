<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RankedResultsCard: the result of a closed ranked preference round as a
 ranking table (issue #1419, REQ-PRF-004). Rank, option and Borda points,
 ordered by rank; the winner carries "Elected", and on a tie every option
 sharing first place carries "Tied". The table shows totals only, so a
 secret round reveals no member's ballot (REQ-PRF-005).

 @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-004-ranked-results-are-displayed-as-a-ranking-table
-->
<template>
	<table class="ranked-results" data-testid="ranked-results">
		<caption class="ranked-results__caption">
			{{
				t('decidiq', 'Ranking by Borda count')
			}}
		</caption>
		<thead>
			<tr>
				<th scope="col">
					{{ t('decidiq', 'Rank') }}
				</th>
				<th scope="col">
					{{ t('decidiq', 'Option') }}
				</th>
				<th scope="col">
					{{ t('decidiq', 'Points') }}
				</th>
			</tr>
		</thead>
		<tbody>
			<tr
				v-for="row in rows"
				:key="row.key"
				:data-testid="`ranked-results-row-${row.key}`">
				<td>{{ row.rank }}</td>
				<td>
					{{ row.label }}
					<span
						v-if="badgeFor(row)"
						class="ranked-results__badge"
						:data-testid="`ranked-results-badge-${row.key}`">
						{{ badgeFor(row) }}
					</span>
				</td>
				<td>{{ row.points }}</td>
			</tr>
		</tbody>
	</table>
</template>

<script>
export default {
	name: 'RankedResultsCard',
	props: {
		round: { type: Object, required: true },
	},

	computed: {
		/**
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-004-ranked-results-are-displayed-as-a-ranking-table
		 * @return {Array} The ranking rows, ordered by rank
		 */
		rows() {
			return [...(this.round.rankingResult || [])].sort(
				(a, b) => (a.rank || 0) - (b.rank || 0),
			)
		},
	},

	methods: {
		/**
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-004-ranked-results-are-displayed-as-a-ranking-table
		 * @param {object} row A ranking row
		 * @return {string} Elected, Tied or ''
		 */
		badgeFor(row) {
			if (
				this.round.result === 'adopted'
				&& row.key === this.round.winningOption
			) {
				return this.t('decidiq', 'Elected')
			}
			if (this.round.result === 'tied' && row.rank === 1) {
				return this.t('decidiq', 'Tied')
			}
			return ''
		},
	},
}
</script>

<style scoped>
.ranked-results {
	border-collapse: collapse;
	width: 100%;
}

.ranked-results__caption {
	color: var(--color-text-maxcontrast);
	text-align: start;
}

.ranked-results th,
.ranked-results td {
	border-bottom: 1px solid var(--color-border);
	padding: var(--default-grid-baseline);
	text-align: start;
}

.ranked-results__badge {
	background-color: var(--color-primary-element-light);
	border-radius: var(--border-radius-pill);
	color: var(--color-primary-element-light-text);
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 var(--default-grid-baseline);
}
</style>
