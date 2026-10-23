<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Enter a named vote taken on paper (board DcStemmenInvoeren, "Hoofdelijke
 stemming op papier invoeren"): for, against or abstain per member, saved
 in one go. The server casts each ballot as the member's own vote, so a
 member who does not sit in the meeting or is recused is refused and named.
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Enter paper votes')"
		size="normal"
		data-testid="record-votes-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="record-votes">
				<fieldset
					v-for="member in participants"
					:key="member.id"
					class="record-votes__member">
					<legend class="record-votes__name">
						{{ member.displayName }}
						<span v-if="member.party" class="record-votes__party">{{
							member.party
						}}</span>
					</legend>
					<NcCheckboxRadioSwitch
						v-for="option in options"
						:key="option.value"
						v-model="choices[member.id]"
						type="radio"
						:value="option.value"
						:name="`paper-vote-${member.id}`">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<p
					v-if="message"
					class="record-votes__message"
					role="alert"
					data-testid="record-votes-message">
					{{ message }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="saving || rows.length === 0"
				data-testid="record-votes-submit"
				@click="save">
				{{ t('decidiq', 'Save votes') }}
			</NcButton>
			<NcButton data-testid="record-votes-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import { paperVoteRows, recordPaperVotes } from '../utils/paperVotes.js'

export default {
	name: 'RecordVotesDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },

	props: {
		/** OR object id of the open voting round. */
		roundId: { type: String, required: true },
		/** The meeting's participants: `{ id, displayName, party }`. */
		participants: { type: Array, default: () => [] },
	},

	emits: ['close', 'recorded'],

	data() {
		return { choices: {}, saving: false, message: '' }
	},

	computed: {
		/** @return {Array<object>} The radio options. */
		options() {
			return [
				{ value: 'for', label: this.t('decidiq', 'For') },
				{ value: 'against', label: this.t('decidiq', 'Against') },
				{ value: 'abstain', label: this.t('decidiq', 'Abstain') },
			]
		},

		/** @return {Array<object>} The ballots that would be sent. */
		rows() {
			return paperVoteRows(this.choices)
		},
	},

	methods: {
		/**
		 * Save the ballots; close when all were taken, else name the refused.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.message = ''
			try {
				const result = await recordPaperVotes(this.roundId, this.choices)
				this.$emit('recorded', result)
				const refused = result?.refused || []
				if (refused.length === 0) {
					this.$emit('close')
					return
				}
				const names = refused.map(
					(row) =>
						this.participants.find(
							(member) => member.id === row.participant,
						)?.displayName || row.participant,
				)
				this.message = this.t('decidiq', 'Not saved: {names}', {
					names: names.join(', '),
				})
			} catch (e) {
				this.message =
					e?.response?.data?.message
					|| this.t('decidiq', 'Try again in a moment.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.record-votes {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.record-votes__member {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	border: 0;
	border-block-end: 1px solid var(--color-border);
	padding: 0 0 calc(var(--default-grid-baseline) * 2);
	margin: 0;
}

.record-votes__name {
	font-weight: bold;
	padding: 0;
}

.record-votes__party {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
}

.record-votes__message {
	color: var(--color-error-text, var(--color-error));
	margin: 0;
}
</style>
