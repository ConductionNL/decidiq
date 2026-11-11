<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The steps an amendment goes through, as one bar across the page.

 Display only. It reads the amendment's `lifecycle` off the record the page
 already loaded and draws five steps: draft, proposed, deliberating, voting,
 decision taken. It moves nothing.

 IT ALSO SAYS WHO TAKES THE NEXT STEP, AND WHERE. Submitting an amendment
 and starting its debate are header buttons, and the server keeps both for
 the chair and the secretary. No endpoint tells a page beforehand whether
 this reader is one of them, so the sentence stands for every reader. The
 vote opens and closes in the Voting round block, where the server holds the
 voting order of the amendments and writes an adopted amendment into the
 motion. A recorded result is said in words.

 @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-003-a-step-bar-shows-where-the-amendment-stands
 @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-004-the-page-says-who-takes-the-next-step-and-where
-->
<template>
	<nav
		class="amendment-steps"
		:aria-label="t('decidiq', 'Steps of this amendment')"
		data-testid="amendment-step-bar">
		<ol class="amendment-steps__list">
			<li
				v-for="(step, index) in steps"
				:key="step.state"
				class="amendment-steps__step"
				:class="'amendment-steps__step--' + step.status"
				:aria-current="step.status === 'current' ? 'step' : undefined"
				:data-testid="'amendment-step-' + step.state">
				<span class="amendment-steps__number" aria-hidden="true">
					{{ index + 1 }}
				</span>
				<span
					class="amendment-steps__label"
					:title="t('decidiq', stepLabel(step.state))">
					{{ t('decidiq', stepLabel(step.state)) }}
				</span>
				<span class="amendment-steps__state">
					{{ stateWord(step.status) }}
				</span>
			</li>
		</ol>
		<p
			v-if="note"
			class="amendment-steps__note"
			role="status"
			:data-testid="'amendment-step-note-' + noteKind">
			{{ note }}
		</p>
	</nav>
</template>

<script>
import { amendmentNote, buildAmendmentSteps } from '../../utils/amendmentSteps.js'
import { STATE_LABELS } from '../tabs/decisionLifecycle.js'
import { stepBarWordsMixin } from './stepBarWords.js'

export default {
	name: 'AmendmentStepBar',
	mixins: [stepBarWordsMixin],
	props: {
		/** The loaded amendment, or null while it is still being fetched. */
		objectData: { type: Object, default: null },
	},

	computed: {
		/**
		 * The five steps, each done, current or upcoming.
		 *
		 * @return {Array<{state: string, status: string}>} The steps.
		 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-003-a-step-bar-shows-where-the-amendment-stands
		 */
		steps() {
			return buildAmendmentSteps(this.objectData?.lifecycle || '')
		},

		/**
		 * Which sentence stands under the steps.
		 *
		 * @return {string} `who`, `vote`, `adopted`, `rejected` or ''.
		 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-004-the-page-says-who-takes-the-next-step-and-where
		 */
		noteKind() {
			return amendmentNote(
				this.objectData?.lifecycle || '',
				this.objectData?.outcome,
			)
		},

		/**
		 * The sentence under the steps, translated.
		 *
		 * @return {string} The sentence, or '' when there is none.
		 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-004-the-page-says-who-takes-the-next-step-and-where
		 */
		note() {
			const sentences = {
				who: this.t(
					'decidiq',
					'Only the chair or the secretary can take the next step.',
				),

				vote: this.t(
					'decidiq',
					'The vote on this amendment opens and closes under Voting round.',
				),

				adopted: this.t('decidiq', 'This amendment was adopted.'),
				rejected: this.t('decidiq', 'This amendment was rejected.'),
			}
			return sentences[this.noteKind] || ''
		},
	},

	methods: {
		/**
		 * The English source label of a state.
		 *
		 * @param {string} state The lifecycle state.
		 * @return {string} Its label, or the state itself when it has none.
		 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-003-a-step-bar-shows-where-the-amendment-stands
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
 * Five steps on ONE row: equal columns that may shrink to nothing, with the
 * number above the label and a word for done, now or next under it. On a
 * narrow page the steps wrap three to a row, and the card grows with them
 * (the layout entry sizes to its content).
 */
.amendment-steps__list {
	grid-template-columns: repeat(5, minmax(0, 1fr));
}

@media (max-width: 700px) {
	.amendment-steps__list {
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}
}
</style>
