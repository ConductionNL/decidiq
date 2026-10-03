<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Swap a member for a substitute in the live meeting
 (bodies-substitute-mandate-swap, ADR-004 modal isolation). The chair or the
 secretary picks the substitute and enters why the member left; the route
 refuses anyone else, and refuses while a vote is open.

 @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Swap with substitute')"
		size="normal"
		data-testid="mandate-swap-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="mandate-swap__form">
				<p>
					{{ t('decidiq', 'Seat {seat}: {name}', { seat: row.seat ?? '-', name: row.name }) }}
				</p>
				<NcSelect
					v-model="substitute"
					:inputLabel="t('decidiq', 'Substitute')"
					:options="options"
					label="label"
					:clearable="false"
					data-testid="mandate-swap-substitute" />
				<NcTextField
					v-model="reason"
					:label="t('decidiq', 'Reason')"
					data-testid="mandate-swap-reason" />
				<p v-if="error" class="mandate-swap__error" role="alert">
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
				:disabled="saving || !substitute"
				data-testid="mandate-swap-confirm"
				@click="save">
				{{ t('decidiq', 'Swap') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { seatsRequest } from '../utils/meetingSeats.js'

export default {
	name: 'MandateSwapModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		/** The meeting. */
		meetingId: { type: String, required: true },
		/** The seat row of the member who leaves: { id, seat, name }. */
		row: { type: Object, required: true },
		/** Who may take the seat: { id, label }. */
		options: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			substitute: null,
			reason: '',
			saving: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Start the swap through the guarded route.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				await seatsRequest('POST', this.meetingId, 'substitutions', {
					outgoingParticipantId: this.row.id,
					incomingParticipantId: this.substitute.id,
					reason: this.reason.trim(),
				})
				this.$emit('saved')
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The swap could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.mandate-swap__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.mandate-swap__error {
	color: var(--color-error-text);
}
</style>
