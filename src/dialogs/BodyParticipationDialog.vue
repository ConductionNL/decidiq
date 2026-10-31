<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Add or edit the participation of an organisation in a shared body
 (bodies-shared-body-participations, bod-13). Saves the BodyParticipation
 through the object store; the payload is built and schema-checked in
 src/utils/bodyParticipations.js.

 @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
-->
<template>
	<NcDialog
		:name="
			participation
				? t('decidiq', 'Edit participation')
				: t('decidiq', 'Add organisation')
		"
		size="normal"
		data-testid="body-participation-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="body-participation__form">
				<NcSelect
					v-model="selectedParticipant"
					:inputLabel="t('decidiq', 'Organisation')"
					:options="organisationOptions"
					:disabled="Boolean(participation)"
					label="label"
					data-testid="body-participation-organisation" />
				<NcTextField
					v-model="seats"
					type="number"
					min="0"
					data-testid="body-participation-seats"
					:label="t('decidiq', 'Seats')" />
				<NcTextField
					v-model="votingWeight"
					type="number"
					min="0"
					step="any"
					data-testid="body-participation-weight"
					:label="t('decidiq', 'Voting weight')" />
				<NcDateTimePickerNative
					id="body-participation-accession"
					v-model="accessionDate"
					type="date"
					data-testid="body-participation-accession"
					:label="t('decidiq', 'Accession date')" />
				<NcDateTimePickerNative
					v-if="participation"
					id="body-participation-exit"
					v-model="exitDate"
					type="date"
					data-testid="body-participation-exit"
					:label="t('decidiq', 'Withdrawal date')" />
				<p
					v-if="error"
					class="body-participation__error"
					role="alert"
					data-testid="body-participation-error">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="saving || !selectedParticipant"
				data-testid="body-participation-submit"
				@click="save">
				{{ saving ? t('decidiq', 'Saving…') : t('decidiq', 'Save') }}
			</NcButton>
			<NcButton
				data-testid="body-participation-cancel"
				@click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDateTimePickerNative,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { ensureRelationType } from '../components/tabs/useRelationStore.js'
import { buildParticipationPayload } from '../utils/bodyParticipations.js'

export default {
	name: 'BodyParticipationDialog',

	components: {
		NcButton,
		NcDateTimePickerNative,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		/** OR object id of the shared body. */
		sharedBodyId: { type: [String, Number], required: true },
		/** The participation being edited, or null to add one. */
		participation: { type: Object, default: null },
		/** The organisations that can take part: [{ id, label }]. */
		organisationOptions: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		const current = this.participation || {}
		return {
			selectedParticipant:
				this.organisationOptions.find((o) => o.id === current.participant)
				|| null,

			seats: current.seats ?? '',
			votingWeight: current.votingWeight ?? '',
			accessionDate: current.accessionDate
				? new Date(current.accessionDate)
				: new Date(),

			exitDate: current.exitDate ? new Date(current.exitDate) : null,
			saving: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Save the participation and tell the widget to reload.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
		 */
		async save() {
			if (!this.selectedParticipant) {
				this.error = this.t('decidiq', 'Choose an organisation.')
				return
			}
			this.saving = true
			this.error = ''
			try {
				await ensureRelationType('body-participation').saveObject(
					'body-participation',
					buildParticipationPayload({
						id: this.participation?.id || '',
						sharedBody: String(this.sharedBodyId),
						participant: this.selectedParticipant.id,
						seats: this.seats,
						votingWeight: this.votingWeight,
						accessionDate: this.accessionDate,
						exitDate: this.exitDate,
						label: this.participation?.label || '',
					}),
				)
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Could not save the participation.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.body-participation__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.body-participation__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
