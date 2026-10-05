<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The steps a decision goes through, as one bar across the page.

 Display only. It reads the decision's `lifecycle` off the record the page
 already loaded and draws the seven states with `buildTimeline`, the same
 model the Lifecycle block draws its list from. It moves nothing: the one
 button that moves a decision is the next-step button in the header, and the
 server decides who may use it.

 A withdrawn decision is on none of the seven steps, so the bar says so in
 words instead of marking a step.

 @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
-->
<template>
	<nav
		class="decision-steps"
		:aria-label="t('decidiq', 'Steps of this decision')"
		data-testid="decision-step-bar">
		<ol class="decision-steps__list">
			<li
				v-for="(step, index) in steps"
				:key="step.state"
				class="decision-steps__step"
				:class="'decision-steps__step--' + step.status"
				:aria-current="step.status === 'current' ? 'step' : undefined"
				:data-testid="'decision-step-' + step.state">
				<span class="decision-steps__number" aria-hidden="true">
					{{ index + 1 }}
				</span>
				<span class="decision-steps__label">
					{{ t('decidiq', stepLabel(step.state)) }}
				</span>
				<span v-if="step.status === 'done'" class="hidden-visually">
					{{ t('decidiq', 'done') }}
				</span>
			</li>
		</ol>
		<p v-if="withdrawn" class="decision-steps__note" role="status">
			{{ t('decidiq', 'This decision was withdrawn.') }}
		</p>
	</nav>
</template>

<script>
import { buildTimeline, STATE_LABELS } from '../tabs/decisionLifecycle.js'

export default {
	name: 'DecisionStepBar',
	props: {
		/** The loaded decision, or null while it is still being fetched. */
		objectData: { type: Object, default: null },
	},

	computed: {
		/**
		 * The seven states, each done, current or upcoming.
		 *
		 * @return {Array<{state: string, status: string}>} The steps.
		 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
		 */
		steps() {
			return buildTimeline(this.objectData?.lifecycle || '')
		},

		/**
		 * Whether the decision left the route by being withdrawn.
		 *
		 * @return {boolean} True for a withdrawn decision.
		 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
		 */
		withdrawn() {
			return this.objectData?.lifecycle === 'withdrawn'
		},
	},

	methods: {
		/**
		 * The English source label of a state.
		 *
		 * @param {string} state The lifecycle state.
		 * @return {string} Its label, or the state itself when it has none.
		 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
		 */
		stepLabel(state) {
			return STATE_LABELS[state] || state
		},
	},
}
</script>

<style scoped>
.decision-steps__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decision-steps__step {
	flex: 1 1 120px;
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2)
		calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.decision-steps__number {
	flex: none;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	border: 2px solid var(--color-border-dark);
}

.decision-steps__step--done {
	color: var(--color-main-text);
}

.decision-steps__step--done .decision-steps__number {
	border-color: var(--color-success);
}

.decision-steps__step--current {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.decision-steps__step--current .decision-steps__number {
	border-color: var(--color-primary-element);
}

.decision-steps__note {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
