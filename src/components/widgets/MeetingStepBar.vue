<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The steps a meeting goes through, as one bar across the page.

 Display only. It reads the meeting's `lifecycle` off the record the page
 already loaded and draws four steps: draft, convened, in session, closed. A
 paused or adjourned meeting stays on the third step, and the bar says the
 break in words. It moves nothing: the one button that moves a meeting is the
 next-step button in the header, and the server decides who may use it.

 IT ALSO SAYS WHO TAKES THE NEXT STEP. Only the chair or the secretary of a
 meeting moves it on. The header button cannot know who is looking, so a
 member sees it too and is refused by the server. This bar asks the server
 which steps it offers this person (GET /api/meetings/{id}/transitions, the
 answer the Stage block draws its buttons from) and says so before anybody
 presses. No answer, no line: it never guesses a role.

 @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
 @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
-->
<template>
	<nav
		class="meeting-steps"
		:aria-label="t('decidiq', 'Steps of this meeting')"
		data-testid="meeting-step-bar">
		<ol class="meeting-steps__list">
			<li
				v-for="(step, index) in steps"
				:key="step.state"
				class="meeting-steps__step"
				:class="'meeting-steps__step--' + step.status"
				:aria-current="step.status === 'current' ? 'step' : undefined"
				:data-testid="'meeting-step-' + step.state">
				<span class="meeting-steps__number" aria-hidden="true">
					{{ index + 1 }}
				</span>
				<span
					class="meeting-steps__label"
					:title="t('decidiq', stepLabel(step.state))">
					{{ t('decidiq', stepLabel(step.state)) }}
				</span>
				<span class="meeting-steps__state">
					{{ stateWord(step.status) }}
				</span>
			</li>
		</ol>
		<p
			v-if="breakNote"
			class="meeting-steps__note"
			role="status"
			data-testid="meeting-step-break">
			{{ breakNote }}
		</p>
		<p
			v-if="refused"
			class="meeting-steps__note"
			role="status"
			data-testid="meeting-step-who">
			{{
				t(
					'decidiq',
					'Only the chair or the secretary of this meeting can take the next step.',
				)
			}}
		</p>
	</nav>
</template>

<script>
import {
	buildMeetingSteps,
	fetchOfferedSteps,
	MEETING_STATE_LABELS,
	nextStepIsOffered,
} from '../../utils/meetingSteps.js'

export default {
	name: 'MeetingStepBar',
	props: {
		/** The meeting's id. */
		objectId: { type: [String, Number], default: '' },
		/** The loaded meeting, or null while it is still being fetched. */
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			/** The steps the server offers this person, or null without an answer. */
			offered: null,
		}
	},

	computed: {
		/**
		 * The meeting's state.
		 *
		 * @return {string} The lifecycle, or '' while the meeting loads.
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		lifecycle() {
			return this.objectData?.lifecycle || ''
		},

		/**
		 * The four steps, each done, current or upcoming.
		 *
		 * @return {Array<{state: string, status: string}>} The steps.
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		steps() {
			return buildMeetingSteps(this.lifecycle)
		},

		/**
		 * The break a meeting in session is in, in words.
		 *
		 * @return {string} The sentence, or '' for a meeting that is not on a break.
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		breakNote() {
			if (this.lifecycle === 'paused') {
				return this.t('decidiq', 'The meeting is paused.')
			}
			return this.lifecycle === 'adjourned'
				? this.t('decidiq', 'The meeting is adjourned.')
				: ''
		},

		/**
		 * Whether the server would refuse this person the next step. Only
		 * true on an answer: without one the bar says nothing.
		 *
		 * @return {boolean} True when the line about the chair and the secretary shows.
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
		 */
		refused() {
			return (
				this.offered !== null
				&& !nextStepIsOffered(this.lifecycle, this.offered)
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step */
			handler() {
				this.loadOffered()
			},
		},

		/** @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step */
		lifecycle() {
			this.loadOffered()
		},
	},

	methods: {
		/**
		 * The English source label of a state.
		 *
		 * @param {string} state The lifecycle state.
		 * @return {string} Its label, or the state itself when it has none.
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		stepLabel(state) {
			return MEETING_STATE_LABELS[state] || state
		},

		/**
		 * Whether a step is behind, current or ahead, in a word. Colour alone
		 * must not carry it.
		 *
		 * @param {string} status `done`, `current` or `upcoming`.
		 * @return {string} The translated word.
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		stateWord(status) {
			if (status === 'done') {
				return this.t('decidiq', 'Done')
			}
			return status === 'current'
				? this.t('decidiq', 'Now')
				: this.t('decidiq', 'Next')
		},

		/**
		 * Ask the server which steps it offers this person, through
		 * `fetchOfferedSteps`. A failed or malformed answer leaves `offered`
		 * null, so no line about roles shows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
		 */
		async loadOffered() {
			this.offered = null
			if (!this.objectId || !this.lifecycle) {
				return
			}
			const asked = `${this.objectId}:${this.lifecycle}`
			const offered = await fetchOfferedSteps(this.objectId)
			// A slower answer for an earlier state must not overwrite a newer one.
			if (asked === `${this.objectId}:${this.lifecycle}`) {
				this.offered = offered
			}
		},
	},
}
</script>

<style scoped>
/*
 * Four steps on ONE row: equal columns that may shrink to nothing, with the
 * number above the label and a word for done, now or next under it. On a
 * narrow page the steps wrap two by two, and the card grows with them (the
 * layout entry sizes to its content).
 *
 * THE STEPS ARE AS TALL AS THE CARD HAS TO BE. The detail grid gives every
 * card at least two rows, and sizing to content cannot go below that floor.
 * A bar one line high would leave an empty band under it, so each step is a
 * tile that fills those two rows. The same measure as the decision page.
 */
.meeting-steps__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: var(--default-grid-baseline);
}

.meeting-steps__step {
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

.meeting-steps__label {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.meeting-steps__state {
	font-size: 0.9em;
	font-weight: normal;
}

.meeting-steps__number {
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

.meeting-steps__step--done {
	color: var(--color-main-text);
}

.meeting-steps__step--done .meeting-steps__number {
	border-color: var(--color-success);
}

.meeting-steps__step--current {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.meeting-steps__step--current .meeting-steps__number {
	border-color: var(--color-primary-element);
}

.meeting-steps__note {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 700px) {
	.meeting-steps__list {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}
</style>
