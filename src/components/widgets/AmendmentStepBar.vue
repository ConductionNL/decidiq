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

export default {
	name: 'AmendmentStepBar',
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

		/**
		 * Whether a step is behind, current or ahead, in a word. Colour alone
		 * must not carry it.
		 *
		 * @param {string} status `done`, `current` or `upcoming`.
		 * @return {string} The translated word.
		 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-003-a-step-bar-shows-where-the-amendment-stands
		 */
		stateWord(status) {
			if (status === 'done') {
				return this.t('decidiq', 'Done')
			}
			return status === 'current'
				? this.t('decidiq', 'Now')
				: this.t('decidiq', 'Next')
		},
	},
}
</script>

<style scoped>
/*
 * Five steps on ONE row: equal columns that may shrink to nothing, with the
 * number above the label and a word for done, now or next under it. On a
 * narrow page the steps wrap three to a row, and the card grows with them
 * (the layout entry sizes to its content).
 *
 * THE STEPS ARE AS TALL AS THE CARD HAS TO BE. The detail grid gives every
 * card at least two rows, and sizing to content cannot go below that floor.
 * A bar one line high would leave an empty band under it, so each step is a
 * tile that fills those two rows. The same measure as the decision page and the meeting page.
 */
.amendment-steps__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(5, minmax(0, 1fr));
	gap: var(--default-grid-baseline);
}

.amendment-steps__step {
	min-width: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	justify-content: center;
	min-height: 124px;
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 2) var(--default-grid-baseline);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-align: center;
}

.amendment-steps__label {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.amendment-steps__state {
	font-size: 0.9em;
	font-weight: normal;
}

.amendment-steps__number {
	flex: none;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	border: 2px solid var(--color-border-dark);
}

.amendment-steps__step--done {
	color: var(--color-main-text);
}

.amendment-steps__step--done .amendment-steps__number {
	border-color: var(--color-success);
}

.amendment-steps__step--current {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.amendment-steps__step--current .amendment-steps__number {
	border-color: var(--color-primary-element);
}

.amendment-steps__note {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 700px) {
	.amendment-steps__list {
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}
}
</style>
