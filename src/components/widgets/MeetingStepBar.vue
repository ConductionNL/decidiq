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

 @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
 @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
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
import { stepBarWordsMixin } from './stepBarWords.js'

export default {
	name: 'MeetingStepBar',
	mixins: [stepBarWordsMixin],
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
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		lifecycle() {
			return this.objectData?.lifecycle || ''
		},

		/**
		 * The four steps, each done, current or upcoming.
		 *
		 * @return {Array<{state: string, status: string}>} The steps.
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		steps() {
			return buildMeetingSteps(this.lifecycle)
		},

		/**
		 * The break a meeting in session is in, in words.
		 *
		 * @return {string} The sentence, or '' for a meeting that is not on a break.
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
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
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
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
			/** @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step */
			handler() {
				this.loadOffered()
			},
		},

		/** @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step */
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
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
		 */
		stepLabel(state) {
			return MEETING_STATE_LABELS[state] || state
		},

		/**
		 * Ask the server which steps it offers this person, through
		 * `fetchOfferedSteps`. A failed or malformed answer leaves `offered`
		 * null, so no line about roles shows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
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

<style scoped src="./stepBar.css"></style>

<style scoped>
/*
 * Four steps on ONE row: equal columns that may shrink to nothing, with the
 * number above the label and a word for done, now or next under it. On a
 * narrow page the steps wrap two by two, and the card grows with them (the
 * layout entry sizes to its content).
 */
.meeting-steps__list {
	grid-template-columns: repeat(4, minmax(0, 1fr));
}

@media (max-width: 700px) {
	.meeting-steps__list {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}
</style>
