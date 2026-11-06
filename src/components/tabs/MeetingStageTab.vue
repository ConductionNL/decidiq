<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting widget: the meeting's stage and the buttons that move it on (pla-15),
 and the cost recorded when the meeting is closed (pla-10).

 The buttons are the steps the server offers the caller (the chair or
 secretary, and only the steps the guarded lifecycle would accept); a member
 sees the stage and no buttons.

 @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
-->
<template>
	<div class="decidiq-tab" data-testid="meeting-stage-tab">
		<p class="decidiq-stage__current">
			{{ t('decidiq', 'Stage') }}:
			<strong data-testid="meeting-stage">{{
				stageLabel(stage.lifecycle)
			}}</strong>
		</p>
		<div v-if="stage.actions.length > 0" class="decidiq-stage__actions">
			<NcButton
				v-for="action in stage.actions"
				:key="action"
				:variant="action === 'close' ? 'error' : 'secondary'"
				:disabled="busy"
				:data-testid="`meeting-stage-${action}`"
				@click="apply(action)">
				{{ actionLabel(action) }}
			</NcButton>
		</div>
		<p
			v-if="cost !== null"
			class="decidiq-stage__cost"
			data-testid="meeting-stage-cost">
			{{ t('decidiq', 'Meeting cost') }}: {{ formatEur(cost) }}
		</p>
		<p v-if="error" class="decidiq-stage__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { formatEur } from '../../utils/meetingCost.js'
import {
	lifecyclePath,
	readStageAnswer,
	recordedCost,
	transitionsPath,
} from '../../utils/meetingStages.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingStageTab',
	components: { NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			stage: readStageAnswer(null),
			cost: null,
			busy: false,
			error: '',
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		formatEur,

		/**
		 * @param {string} lifecycle The stage
		 * @return {string}
		 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
		 */
		stageLabel(lifecycle) {
			const labels = {
				draft: this.t('decidiq', 'Draft'),
				scheduled: this.t('decidiq', 'Convened'),
				opened: this.t('decidiq', 'In session'),
				paused: this.t('decidiq', 'Paused'),
				adjourned: this.t('decidiq', 'Adjourned'),
				closed: this.t('decidiq', 'Closed'),
			}
			return labels[lifecycle] || lifecycle || '-'
		},

		/**
		 * @param {string} action The step
		 * @return {string}
		 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
		 */
		actionLabel(action) {
			const labels = {
				schedule: this.t('decidiq', 'Convene meeting'),
				open: this.t('decidiq', 'Open meeting'),
				pause: this.t('decidiq', 'Pause meeting'),
				resume: this.t('decidiq', 'Resume meeting'),
				adjourn: this.t('decidiq', 'Adjourn meeting'),
				close: this.t('decidiq', 'Close meeting'),
			}
			return labels[action] || action
		},

		/**
		 * Ask the server for the stage and the steps the caller may take, and
		 * read the recorded cost off the meeting. Fails closed: without an
		 * answer no button shows.
		 *
		 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
		 */
		async load() {
			this.stage = readStageAnswer(null)
			if (!this.objectId) return
			try {
				const response = await fetch(
					generateUrl(transitionsPath(this.objectId)),
					{ headers: { Accept: 'application/json' } },
				)
				if (response.ok) {
					this.stage = readStageAnswer(await response.json())
				}
			} catch {
				this.stage = readStageAnswer(null)
			}
			try {
				const meeting = await ensureRelationType('meeting').fetchObject(
					'meeting',
					this.objectId,
				)
				this.cost = recordedCost(meeting)
			} catch {
				this.cost = null
			}
		},

		/**
		 * Apply one step through the guarded lifecycle, then show the new
		 * stage, the steps it offers and, after closing, the cost.
		 *
		 * @param {string} action The step
		 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
		 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
		 */
		async apply(action) {
			this.error = ''
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(lifecyclePath(this.objectId)),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ action }),
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
				const cost = recordedCost(payload?.meeting)
				if (cost !== null) {
					this.cost = cost
				}
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
