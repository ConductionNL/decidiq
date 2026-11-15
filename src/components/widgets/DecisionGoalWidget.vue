<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The goal a decision serves, on the decision's page, as a link to the goal's
 own page (goals-linked-to-decisions REQ-010). The goal is picked in the
 decision form and in the Governance data widget; this widget only shows it.
 A custom widget because the shared data widget shows a reference by name,
 not as a link.

 @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
-->
<template>
	<div class="decision-goal" data-testid="decision-goal">
		<p v-if="!goalId" class="decision-goal__muted">
			{{ t('decidiq', 'This decision serves no goal.') }}
		</p>
		<p v-else>
			<router-link
				class="decision-goal__link"
				:to="location"
				data-testid="decision-goal-link">
				{{ label }}
			</router-link>
		</p>
	</div>
</template>

<script>
import { goalIdOf, goalLabel, goalLocation } from '../../utils/decisionGoal.js'
import { ensureRelationType } from '../tabs/useRelationStore.js'

export default {
	name: 'DecisionGoalWidget',

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			goalId: '',
			goal: null,
		}
	},

	computed: {
		location() {
			return goalLocation(this.goalId)
		},

		label() {
			return goalLabel(this.goal, this.goalId)
		},
	},

	watch: {
		objectId: { immediate: true, handler() { this.load() } },
	},

	methods: {
		/**
		 * Read the decision, then its goal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
		 */
		async load() {
			this.goalId = ''
			this.goal = null
			if (!this.objectId) {
				return
			}
			try {
				const decision = await ensureRelationType('decision').fetchObject('decision', String(this.objectId))
				this.goalId = goalIdOf(decision)
				if (this.goalId) {
					this.goal = await ensureRelationType('goal').fetchObject('goal', this.goalId)
				}
			} catch {
				// An unreadable decision or goal shows as no link, never an error.
				this.goal = null
			}
		},
	},
}
</script>

<style scoped>
.decision-goal__muted {
	color: var(--color-text-maxcontrast);
}

.decision-goal__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
