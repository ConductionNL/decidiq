<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The steps of a goal, Draft, Active and Achieved, with their dates, as one bar
 across the goal page (board DcDoel, goals-linked-to-decisions REQ-014).
 Display only: it reads the goal the page already loaded.

 @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
-->
<template>
	<nav
		class="goal-steps"
		:aria-label="t('decidiq', 'Steps of this goal')"
		data-testid="goal-step-bar">
		<ol class="goal-steps__list">
			<li
				v-for="(step, index) in steps"
				:key="step.key"
				class="goal-steps__step"
				:class="'goal-steps__step--' + step.status"
				:aria-current="step.status === 'current' ? 'step' : undefined"
				:data-testid="'goal-step-' + step.key">
				<span class="goal-steps__number" aria-hidden="true">{{ index + 1 }}</span>
				<span class="goal-steps__label">{{ t('decidiq', step.label) }}</span>
				<span v-if="step.date" class="goal-steps__date">{{ shortDate(step.date) }}</span>
				<span class="goal-steps__state">{{ stateWord(step.status) }}</span>
			</li>
		</ol>
		<p v-if="abandoned" class="goal-steps__note" role="status">
			{{ t('decidiq', 'This goal was abandoned.') }}
		</p>
	</nav>
</template>

<script>
import { goalSteps, shortDate } from '../../utils/goalPage.js'
import { stepBarWordsMixin } from './stepBarWords.js'

export default {
	name: 'GoalStepBar',
	mixins: [stepBarWordsMixin],

	props: {
		/** The loaded goal, or null while it is still being fetched. */
		objectData: { type: Object, default: null },
	},

	computed: {
		/**
		 * The three steps, each done, current or upcoming.
		 *
		 * @return {Array<object>} The steps.
		 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
		 */
		steps() {
			return goalSteps(this.objectData)
		},

		/**
		 * Whether the goal was given up, and so stands on no step.
		 *
		 * @return {boolean}
		 */
		abandoned() {
			return this.objectData?.status === 'abandoned'
		},
	},

	methods: {
		shortDate,
	},
}
</script>

<style scoped>
.goal-steps__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: var(--default-grid-baseline);
}

.goal-steps__step {
	min-width: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	padding: calc(var(--default-grid-baseline) * 2) var(--default-grid-baseline);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.goal-steps__step--done {
	color: var(--color-main-text);
}

.goal-steps__step--current {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.goal-steps__number {
	font-weight: bold;
}

.goal-steps__date,
.goal-steps__state {
	font-size: 0.9em;
	font-weight: normal;
}

.goal-steps__note {
	margin-top: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}
</style>
