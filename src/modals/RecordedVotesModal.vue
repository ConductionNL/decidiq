<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The entry sheet of a roll call round (board DcStemmenInvoeren): the chair or
 the secretary records for, against, abstain or "did not vote" for each
 active member, or for a whole party at once, and saves the list in one go.
 The server refuses a wrong list as a whole; the members it names are shown
 and the entries are kept, so the sheet can be corrected and saved again.

 @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Record votes by name')"
		size="normal"
		data-testid="recorded-votes-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="recorded-votes">
				<p v-if="members.length === 0" data-testid="recorded-votes-empty">
					{{ t('decidiq', 'This meeting has no active participants.') }}
				</p>

				<div
					v-if="parties.length > 0"
					class="recorded-votes__party-control"
					data-testid="recorded-votes-party-control">
					<NcSelect
						v-model="partyChoice.party"
						:options="parties"
						:inputLabel="t('decidiq', 'Party')"
						:clearable="false" />
					<NcSelect
						v-model="partyChoice.value"
						:options="choiceOptions"
						label="label"
						:inputLabel="t('decidiq', 'Vote of the party')"
						:clearable="false" />
					<NcButton
						:disabled="!partyChoice.party || !partyChoice.value"
						data-testid="recorded-votes-party-apply"
						@click="applyParty">
						{{ t('decidiq', 'Apply to party') }}
					</NcButton>
				</div>

				<fieldset
					v-for="member in members"
					:key="member.id"
					class="recorded-votes__member"
					:class="{ 'recorded-votes__member--refused': refusedIds.includes(member.id) }"
					:data-testid="`recorded-votes-member-${member.id}`">
					<legend class="recorded-votes__name">
						{{ member.displayName || member.id }}
						<span v-if="member.party" class="recorded-votes__party">{{
							member.party
						}}</span>
					</legend>
					<NcCheckboxRadioSwitch
						v-for="option in choiceOptions"
						:key="option.value"
						v-model="choices[member.id]"
						type="radio"
						:value="option.value"
						:name="`roll-call-${member.id}`">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<p
					v-if="message"
					class="recorded-votes__message"
					role="alert"
					data-testid="recorded-votes-message">
					{{ message }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="saving || entries.length === 0"
				data-testid="recorded-votes-submit"
				@click="save">
				{{ t('decidiq', 'Save votes') }}
			</NcButton>
			<NcButton data-testid="recorded-votes-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect } from '@nextcloud/vue'
import {
	activeMembers,
	applyPartyChoice,
	namedVoteEntries,
	partiesOf,
	recordNamedVotes,
	refusedNames,
} from '../utils/namedVotes.js'

export default {
	name: 'RecordedVotesModal',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect },

	props: {
		/** OR object id of the open roll call round. */
		roundId: { type: String, required: true },
		/** The meeting's participants: `{ id, displayName, party, leftAt }`. */
		participants: { type: Array, default: () => [] },
	},

	emits: ['close', 'recorded'],

	data() {
		return {
			choices: {},
			partyChoice: { party: null, value: null },
			saving: false,
			message: '',
			refusedIds: [],
		}
	},

	computed: {
		/** @return {Array<object>} The members on the sheet. */
		members() {
			return activeMembers(this.participants)
		},

		/** @return {string[]} The parties of those members. */
		parties() {
			return partiesOf(this.members)
		},

		/** @return {Array<object>} The choices of a row; empty means did not vote. */
		choiceOptions() {
			return [
				{ value: 'for', label: this.t('decidiq', 'For') },
				{ value: 'against', label: this.t('decidiq', 'Against') },
				{ value: 'abstain', label: this.t('decidiq', 'Abstain') },
				{ value: '', label: this.t('decidiq', 'Did not vote') },
			]
		},

		/** @return {Array<object>} The entries that would be sent. */
		entries() {
			return namedVoteEntries(this.choices, this.members)
		},
	},

	methods: {
		/** Set the chosen vote for every member of the chosen party. */
		applyParty() {
			const { party, value } = this.partyChoice
			if (!party || !value) return
			this.choices = applyPartyChoice(
				this.choices,
				this.members,
				party,
				value.value,
			)
		},

		/**
		 * Save the list; close when it was taken, else name the refused members
		 * and keep every entry.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.message = ''
			this.refusedIds = []
			try {
				const result = await recordNamedVotes(this.roundId, this.entries)
				this.$emit('recorded', result)
				this.$emit('close')
			} catch (e) {
				const refusal = e?.response?.data || {}
				const names = refusedNames(refusal, this.members)
				this.refusedIds = Array.isArray(refusal.participantIds)
					? refusal.participantIds
					: []
				if (names.length > 0) {
					this.message = this.t('decidiq', 'Not saved: {names}', {
						names: names.join(', '),
					})
				} else {
					this.message =
						refusal.message || this.t('decidiq', 'Try again in a moment.')
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.recorded-votes {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.recorded-votes__party-control {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-block-end: calc(var(--default-grid-baseline) * 2);
	border-block-end: 1px solid var(--color-border);
}

.recorded-votes__member {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	border: 0;
	border-block-end: 1px solid var(--color-border);
	padding: 0 0 calc(var(--default-grid-baseline) * 2);
	margin: 0;
}

.recorded-votes__member--refused {
	box-shadow: inset 3px 0 0 0 var(--color-error);
	padding-inline-start: calc(var(--default-grid-baseline) * 2);
}

.recorded-votes__name {
	font-weight: bold;
	padding: 0;
}

.recorded-votes__party {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
}

.recorded-votes__message {
	color: var(--color-error-text, var(--color-error));
	margin: 0;
}
</style>
