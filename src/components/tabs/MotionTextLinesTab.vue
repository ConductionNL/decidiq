<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion page widget: the motion text with stable line numbers, and, for the
 chair or secretary of the motion's meeting, "Recommend a change" on a range
 of lines (motion-change-recommendations).

 @custom-widget-ratchet exclude The line view computes numbered lines from the
 motion text; no data widget numbers lines or opens a dialog per line range.

 @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--text-lines"
		data-testid="motion-text-lines-tab">
		<div class="decidiq-tab__header">
			<NcCheckboxRadioSwitch
				v-model="showNumbers"
				type="switch"
				data-testid="motion-text-lines-toggle">
				{{ t('decidiq', 'Line numbers') }}
			</NcCheckboxRadioSwitch>
			<NcButton
				v-if="canDecide && lines.length > 0"
				data-testid="motion-text-lines-recommend"
				@click="dialogOpen = true">
				{{ t('decidiq', 'Recommend a change') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load the motion text')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="!loading && lines.length === 0" class="decidiq-tab__empty">
			{{ t('decidiq', 'This motion has no text yet.') }}
		</p>

		<ol v-else-if="showNumbers" class="decidiq-text-lines">
			<li
				v-for="line in lines"
				:key="line.number"
				class="decidiq-text-lines__line"
				:data-line="line.number">
				<span class="decidiq-text-lines__number" aria-hidden="true">{{
					line.number
				}}</span>
				<span class="decidiq-text-lines__text">{{ line.text }}</span>
			</li>
		</ol>

		<p v-else class="decidiq-text-lines__plain">
			{{ motionText }}
		</p>

		<p v-if="saved" class="decidiq-tab__notice" role="status">
			{{
				t(
					'decidiq',
					'Your recommendation is listed under Recommended changes.',
				)
			}}
		</p>

		<ChangeRecommendationDialog
			v-if="dialogOpen"
			:text="motionText"
			:busy="saving"
			:error="saveError"
			@submit="recommend"
			@close="dialogOpen = false" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ChangeRecommendationDialog from '../../dialogs/ChangeRecommendationDialog.vue'
import { newRecommendation } from '../../utils/changeRecommendations.js'
import { numberLines } from '../../utils/lineNumbering.js'
import { motionRecommendationContext } from './motionRecommendationContext.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MotionTextLinesTab',

	components: {
		ChangeRecommendationDialog,
		CnNoteCard,
		NcButton,
		NcCheckboxRadioSwitch,
	},

	mixins: [motionRecommendationContext],

	data() {
		return {
			loading: false,
			error: '',
			showNumbers: true,
			dialogOpen: false,
			saving: false,
			saveError: '',
			saved: false,
		}
	},

	computed: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers */
		lines() {
			return numberLines(this.motionText)
		},
	},

	watch: {
		motionId: {
			immediate: true,
			/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers */
		async refresh() {
			this.loading = true
			this.error = ''
			try {
				await this.loadMotionContext()
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load the motion.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the dialog's answer as a pending recommendation on this motion.
		 *
		 * @param {object} answer The dialog's answer.
		 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording
		 */
		async recommend(answer) {
			this.saving = true
			this.saveError = ''
			try {
				await ensureRelationType('change-recommendation').saveObject(
					'change-recommendation',
					newRecommendation(this.motionId, answer),
				)
				this.dialogOpen = false
				this.saved = true
			} catch (e) {
				this.saveError = e?.message || this.t('decidiq', 'Save failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped src="./relationTab.css"></style>

<style scoped>
.decidiq-text-lines {
	list-style: none;
	margin: 0;
	padding: 0;
	font-family: var(--font-face-monospace, monospace);
}

.decidiq-text-lines__line {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-text-lines__number {
	min-width: 3ch;
	text-align: end;
	color: var(--color-text-maxcontrast);
	user-select: none;
}

.decidiq-text-lines__plain {
	white-space: pre-wrap;
}
</style>
