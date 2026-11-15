<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: the chair or secretary records the decision taken on the current
 agenda item, during the live meeting. Posts to
 POST /api/meetings/{id}/live-decisions with the item, the outcome and the
 decision type (modal isolation, ADR-004).

 @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Record a decision')"
		data-testid="live-decision-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-live-decision-form">
				<NcTextField
					v-model="title"
					data-testid="live-decision-title"
					:label="t('decidiq', 'Decision')" />
				<NcTextArea
					v-model="text"
					data-testid="live-decision-text"
					:label="t('decidiq', 'Decision text')"
					resize="vertical" />
				<NcSelect
					v-model="outcome"
					:options="outcomeOptions"
					:inputLabel="t('decidiq', 'Outcome')"
					:clearable="false"
					data-testid="live-decision-outcome" />
				<NcSelect
					v-model="decisionType"
					:options="typeOptions"
					:inputLabel="t('decidiq', 'Kind of decision')"
					:clearable="false"
					data-testid="live-decision-type" />
				<p v-if="error" class="decidiq-error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="live-decision-submit"
				:disabled="busy || !title.trim() || !text.trim()"
				@click="submit">
				{{ t('decidiq', 'Record decision') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { liveDecisionBody } from '../utils/liveMeeting.js'

export default {
	name: 'LiveDecisionDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextArea, NcTextField },

	props: {
		/** The meeting in session */
		meetingId: { type: String, required: true },
		/** The current agenda item */
		item: { type: Object, required: true },
	},

	emits: ['recorded', 'close'],

	data() {
		const outcomeOptions = [
			{ id: 'adopted', label: this.t('decidiq', 'Adopted') },
			{ id: 'rejected', label: this.t('decidiq', 'Rejected') },
		]
		const typeOptions = [
			{ id: 'resolution', label: this.t('decidiq', 'Resolution') },
			{ id: 'motion', label: this.t('decidiq', 'Motion') },
			{ id: 'amendment', label: this.t('decidiq', 'Amendment') },
			{ id: 'appointment', label: this.t('decidiq', 'Appointment') },
		]
		return {
			outcomeOptions,
			typeOptions,
			title: this.item?.title ?? '',
			text: '',
			outcome: outcomeOptions[0],
			decisionType: typeOptions[0],
			busy: false,
			error: '',
		}
	},

	methods: {
		/** @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				const response = await fetch(
					generateUrl(
						`/apps/decidiq/api/meetings/${this.meetingId}/live-decisions`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(
							liveDecisionBody(
								{
									title: this.title,
									text: this.text,
									outcome: this.outcome?.id,
									decisionType: this.decisionType?.id,
								},
								this.item?.id,
							),
						),
					},
				)
				if (!response.ok) {
					this.error =
						response.status === 409
							? this.t(
									'decidiq',
									'A decision can only be recorded while the meeting is in session.',
								)
							: this.t(
									'decidiq',
									'The decision could not be recorded.',
								)
					return
				}
				this.$emit('recorded')
			} catch {
				this.error = this.t('decidiq', 'The decision could not be recorded.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-live-decision-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-error {
	color: var(--color-error-text);
	margin: 0;
}
</style>
