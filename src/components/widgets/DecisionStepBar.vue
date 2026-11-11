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
				<span
					class="decision-steps__label"
					:title="t('decidiq', stepLabel(step.state))">
					{{ t('decidiq', stepLabel(step.state)) }}
				</span>
				<span class="decision-steps__state">
					{{ stateWord(step.status) }}
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
import { stepBarWordsMixin } from './stepBarWords.js'

export default {
	name: 'DecisionStepBar',
	mixins: [stepBarWordsMixin],
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

<style scoped src="./stepBar.css"></style>

<style scoped>
/*
 * Seven steps on ONE row. Equal columns that may shrink to nothing, with the
 * number above the label, so the row fits a 1440 px page beside the side
 * column and the card is one row high. A label that does not fit is cut with
 * an ellipsis and keeps its full text as a tooltip. On a narrow page the
 * steps wrap, and the card grows with them (the layout entry sizes to its
 * content).
 */
.decision-steps__list {
	grid-template-columns: repeat(7, minmax(0, 1fr));
}

@media (max-width: 700px) {
	.decision-steps__list {
		grid-template-columns: repeat(4, minmax(0, 1fr));
	}
}
</style>
