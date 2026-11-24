<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The parent goal card (board DcDoel, goals-linked-to-decisions REQ-014): the
 parent's title as a link and how many of its sub-goals are reached ("3
 sub-goals, of which 1 reached"). A top-level goal says it has no parent.

 @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
-->
<template>
	<div class="goal-parent" data-testid="goal-parent">
		<p v-if="!parentId" class="goal-parent__muted">
			{{ t('decidiq', 'This goal has no parent goal.') }}
		</p>
		<template v-else>
			<p>
				<router-link class="goal-parent__link" :to="location" data-testid="goal-parent-link">
					{{ label }}
				</router-link>
			</p>
			<p v-if="summary.count > 0" class="goal-parent__summary" data-testid="goal-parent-summary">
				{{ n('decidiq', '%n sub-goal, of which {achieved} reached', '%n sub-goals, of which {achieved} reached', summary.count, { achieved: summary.achieved }) }}
			</p>
		</template>
	</div>
</template>

<script>
import { fetchGoal, fetchSubgoals } from '../../services/goalContributions.js'
import { goalLabel, goalLocation } from '../../utils/decisionGoal.js'
import { parentGoalIdOf, parentSummary } from '../../utils/goalPage.js'

export default {
	name: 'GoalParentWidget',

	props: {
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			parent: null,
			siblings: [],
		}
	},

	computed: {
		parentId() {
			return parentGoalIdOf(this.objectData)
		},

		location() {
			return goalLocation(this.parentId)
		},

		label() {
			return goalLabel(this.parent, this.parentId)
		},

		/**
		 * The parent's sub-goals, counted.
		 *
		 * @return {{count: number, achieved: number}}
		 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
		 */
		summary() {
			return parentSummary(this.siblings)
		},
	},

	watch: {
		parentId: { immediate: true, handler() { this.load() } },
	},

	methods: {
		/**
		 * Read the parent and its sub-goals.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
		 */
		async load() {
			this.parent = null
			this.siblings = []
			if (!this.parentId) {
				return
			}
			const [parent, siblings] = await Promise.all([
				fetchGoal(this.parentId),
				fetchSubgoals(this.parentId),
			])
			this.parent = parent
			this.siblings = siblings
		},
	},
}
</script>

<style scoped>
.goal-parent__muted,
.goal-parent__summary {
	color: var(--color-text-maxcontrast);
}

.goal-parent__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
