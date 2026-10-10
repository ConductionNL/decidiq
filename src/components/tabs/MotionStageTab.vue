<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion widget: the motion's stage and result, and the buttons that move it
 on (mot-08).

 The buttons are the steps the server offers the caller: the chair or
 secretary of the motion's meeting gets every step of the motion lifecycle,
 the member who submitted the motion gets Withdraw, anyone else sees the
 stage and no buttons.

 @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
-->
<template>
	<div class="decidiq-tab" data-testid="motion-stage-tab">
		<p class="decidiq-stage__current">
			{{ t('decidiq', 'Stage') }}:
			<strong data-testid="motion-stage">{{
				stageLabel(stage.lifecycle)
			}}</strong>
			<template v-if="stage.outcome">
				({{ outcomeLabel(stage.outcome) }})
			</template>
		</p>
		<div v-if="stage.actions.length > 0" class="decidiq-stage__actions">
			<NcButton
				v-for="action in stage.actions"
				:key="actionKey(action)"
				:variant="action.to === 'withdrawn' ? 'error' : 'secondary'"
				:disabled="busy"
				:data-testid="`motion-stage-${actionKey(action)}`"
				@click="apply(action)">
				{{ actionLabel(action) }}
			</NcButton>
		</div>
		<p v-if="error" class="decidiq-stage__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import {
	actionKey,
	readMotionStageAnswer,
	transitionPath,
	transitionsPath,
} from '../../utils/motionStages.js'

export default {
	name: 'MotionStageTab',
	components: { NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			stage: readMotionStageAnswer(null),
			busy: false,
			error: '',
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		actionKey,

		/**
		 * @param {string} lifecycle The stage
		 * @return {string}
		 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
		 */
		stageLabel(lifecycle) {
			const labels = {
				draft: this.t('decidiq', 'Draft'),
				proposed: this.t('decidiq', 'Submitted'),
				deliberating: this.t('decidiq', 'In debate'),
				voting: this.t('decidiq', 'Being voted on'),
				decided: this.t('decidiq', 'Decision taken'),
				enacted: this.t('decidiq', 'Carried out'),
				archived: this.t('decidiq', 'Archived'),
				withdrawn: this.t('decidiq', 'Withdrawn'),
			}
			return labels[lifecycle] || lifecycle || '-'
		},

		/**
		 * @param {string} outcome The result
		 * @return {string}
		 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
		 */
		outcomeLabel(outcome) {
			const labels = {
				adopted: this.t('decidiq', 'Adopted'),
				rejected: this.t('decidiq', 'Rejected'),
			}
			return labels[outcome] || outcome
		},

		/**
		 * @param {{to: string, outcome?: string}} action The step
		 * @return {string}
		 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
		 */
		actionLabel(action) {
			const labels = {
				proposed: this.t('decidiq', 'Submit motion'),
				deliberating: this.t('decidiq', 'Start the debate'),
				voting: this.t('decidiq', 'Put to the vote'),
				'decided-adopted': this.t('decidiq', 'Record as adopted'),
				'decided-rejected': this.t('decidiq', 'Record as rejected'),
				enacted: this.t('decidiq', 'Mark as carried out'),
				archived: this.t('decidiq', 'Archive motion'),
				withdrawn: this.t('decidiq', 'Withdraw motion'),
			}
			return labels[actionKey(action)] || action.to
		},

		/**
		 * Ask the server for the stage and the steps the caller may take.
		 * Fails closed: without an answer no button shows.
		 *
		 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
		 */
		async load() {
			this.stage = readMotionStageAnswer(null)
			if (!this.objectId) return
			try {
				const response = await fetch(
					generateUrl(transitionsPath(this.objectId)),
					{ headers: { Accept: 'application/json' } },
				)
				if (response.ok) {
					this.stage = readMotionStageAnswer(await response.json())
				}
			} catch {
				this.stage = readMotionStageAnswer(null)
			}
		},

		/**
		 * Apply one step through the guarded transition, then show the new
		 * stage and the steps it offers.
		 *
		 * @param {{to: string, outcome?: string}} action The step
		 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
		 */
		async apply(action) {
			this.error = ''
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(transitionPath(this.objectId)),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							newState: action.to,
							outcome: action.outcome || null,
						}),
					},
				)
				const payload = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.error =
						payload?.message
						|| this.t('decidiq', 'The stage was not changed.')
					return
				}
				await this.load()
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'The stage was not changed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-stage__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-block: calc(var(--default-grid-baseline, 4px) * 2);
}

.decidiq-stage__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
