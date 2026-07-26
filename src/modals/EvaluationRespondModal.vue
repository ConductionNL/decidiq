<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Anonymous respond form for a board self-evaluation cycle
 (board-self-evaluation, REQ-EVAL-003). This form never asks for or displays
 the responding member's identity — the server derives it from the session
 solely to check the invited roster and compute the opaque dedup token; it is
 never persisted on the response content. Submission is handled by the parent
 via the `confirm` event so the anonymous-submission API call happens in one
 place (GovernanceBodyEvaluationsTab).

 @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Board self-evaluation')"
		size="large"
		data-testid="evaluation-respond-modal"
		@closing="$emit('close')">
		<template #default>
			<p class="evaluation-respond__intro">
				{{ t('decidesk', 'Your response is anonymous. It cannot be traced back to you, even by an administrator.') }}
			</p>

			<div v-for="question in questions" :key="question.id" class="evaluation-respond__question">
				<p class="evaluation-respond__prompt">{{ question.prompt }}</p>

				<div v-if="question.type === 'likert'"
					class="evaluation-respond__likert"
					role="radiogroup"
					:aria-label="question.prompt">
					<NcButton
						v-for="value in likertRange(question)"
						:key="value"
						:variant="answers[question.id] === value ? 'primary' : 'secondary'"
						:data-testid="`evaluation-respond-likert-${question.id}-${value}`"
						@click="setLikert(question.id, value)">
						{{ value }}
					</NcButton>
				</div>

				<NcTextArea
					v-else
					v-model="freeTexts[question.id]"
					:label="question.prompt"
					data-testid="evaluation-respond-freetext"
					resize="vertical" />
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="evaluation-respond-submit"
				:disabled="!allLikertAnswered"
				@click="submit">
				{{ t('decidesk', 'Submit anonymously') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

/**
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
 */
export default {
	name: 'EvaluationRespondModal',

	components: { NcButton, NcDialog, NcTextArea },

	props: {
		/** EvaluationTemplate.questions[] for the evaluation being responded to. */
		questions: { type: Array, default: () => [] },
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			answers: {},
			freeTexts: {},
		}
	},

	computed: {
		/** @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member */
		allLikertAnswered() {
			return this.questions
				.filter((q) => q.type === 'likert')
				.every((q) => this.answers[q.id] !== undefined)
		},
	},

	methods: {
		/**
		 * @param {object} question The likert question.
		 * @return {number[]} The scale values from scaleMin to scaleMax.
		 */
		likertRange(question) {
			const min = Number.isFinite(question.scaleMin) ? question.scaleMin : 1
			const max = Number.isFinite(question.scaleMax) ? question.scaleMax : 5
			const range = []
			for (let value = min; value <= max; value += 1) {
				range.push(value)
			}
			return range
		},
		/**
		 * @param {string} questionId The question id.
		 * @param {number} value The selected Likert value.
		 */
		setLikert(questionId, value) {
			this.answers[questionId] = value
		},
		/** Build the answers[] payload and emit confirm — no identity anywhere in it. */
		submit() {
			const answers = this.questions.map((question) => {
				if (question.type === 'likert') {
					return { questionId: question.id, dimension: question.dimension, likertValue: this.answers[question.id] }
				}
				return { questionId: question.id, dimension: question.dimension, freeText: this.freeTexts[question.id] || '' }
			}).filter((answer) => answer.likertValue !== undefined || (answer.freeText && answer.freeText.trim() !== ''))

			this.$emit('confirm', answers)
		},
	},
}
</script>

<style scoped>
.evaluation-respond__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}
.evaluation-respond__question {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}
.evaluation-respond__prompt {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline);
}
.evaluation-respond__likert {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
</style>
