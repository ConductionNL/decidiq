<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The progress card of a goal (board DcDoel): the target value and the
 commitments, action items and sub-goals that point at it, each as "x of y"
 with a bar. A line with nothing to count is left out
 (goals-linked-to-decisions REQ-012).

 A custom widget because the shared stats widget shows one number per tile,
 not a count against a total. The counts are read from the objects that name
 the goal, so they are current without waiting for the goal's materialised
 rates.

 @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
-->
<template>
	<div class="goal-progress" data-testid="goal-progress">
		<p v-if="loading" class="goal-progress__muted">
			{{ t('decidiq', 'Loading progress …') }}
		</p>
		<p v-else-if="lines.length === 0" class="goal-progress__muted">
			{{ t('decidiq', 'Nothing to measure yet.') }}
		</p>
		<ul v-else class="goal-progress__lines">
			<li
				v-for="line in lines"
				:key="line.key"
				class="goal-progress__line"
				:data-testid="'goal-progress-' + line.key">
				<span class="goal-progress__label">{{ t('decidiq', line.label) }}</span>
				<span class="goal-progress__value">
					{{ t('decidiq', '{current} of {total}', { current: line.current, total: line.total }) }}
					<template v-if="line.unit">{{ line.unit }}</template>
				</span>
				<span
					class="goal-progress__bar"
					role="progressbar"
					:aria-label="t('decidiq', line.label)"
					aria-valuemin="0"
					:aria-valuemax="line.total"
					:aria-valuenow="line.current">
					<span class="goal-progress__fill" :style="{ width: percent(line) + '%' }" />
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { fetchContributions, fetchSubgoals } from '../../services/goalContributions.js'
import { linePercent, objectIdOf, progressLines } from '../../utils/goalPage.js'

export default {
	name: 'GoalProgressWidget',

	props: {
		objectId: { type: [String, Number], default: '' },
		/** The loaded goal, or null while it is still being fetched. */
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			loading: false,
			commitments: [],
			actionItems: [],
			subgoals: [],
		}
	},

	computed: {
		goalId() {
			return String(this.objectId || objectIdOf(this.objectData) || '')
		},

		/**
		 * The lines to show, without the ones whose total is zero.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
		 */
		lines() {
			return progressLines(this.objectData, {
				commitments: this.commitments,
				actionItems: this.actionItems,
				subgoals: this.subgoals,
			})
		},
	},

	watch: {
		goalId: { immediate: true, handler() { this.load() } },
	},

	methods: {
		percent: linePercent,

		/**
		 * Read what points at the goal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
		 */
		async load() {
			if (!this.goalId) {
				return
			}
			this.loading = true
			try {
				const [byKind, subgoals] = await Promise.all([
					fetchContributions(this.goalId),
					fetchSubgoals(this.goalId),
				])
				this.commitments = byKind.commitment || []
				this.actionItems = byKind['action-item'] || []
				this.subgoals = subgoals
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.goal-progress__muted {
	color: var(--color-text-maxcontrast);
}

.goal-progress__lines {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.goal-progress__line {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: var(--default-grid-baseline);
}

.goal-progress__value {
	font-weight: bold;
	text-align: end;
}

.goal-progress__bar {
	grid-column: 1 / -1;
	display: block;
	height: 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-darker);
	overflow: hidden;
}

.goal-progress__fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
}
</style>
