<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — change a member's term (ADR-004 modal isolation).

 The edit side of the Leden form for one member's term: "Termijn begint"
 and "Termijn eindigt", stored on the Membership's startDate and endDate
 (bodies-term-of-office-and-step-down, bod-07). An end date may lie in the
 future; once it has passed the member shows under past members, and
 nothing is written when that happens.

 @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Change term')"
		size="small"
		data-testid="member-term-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="member-term__form">
				<p data-testid="member-term-member">
					{{ t('decidiq', 'The term of {name}.', { name: memberName }) }}
				</p>
				<NcDateTimePickerNative
					id="member-term-start"
					v-model="startDate"
					type="date"
					data-testid="member-term-start"
					:label="t('decidiq', 'Term start date')" />
				<NcDateTimePickerNative
					id="member-term-end"
					v-model="endDate"
					type="date"
					data-testid="member-term-end"
					:label="t('decidiq', 'Term end date')" />
				<p
					v-if="error"
					class="member-term__error"
					role="alert"
					data-testid="member-term-error">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="member-term-save"
				@click="save">
				{{ saving ? t('decidiq', 'Saving…') : t('decidiq', 'Save') }}
			</NcButton>
			<NcButton data-testid="member-term-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDateTimePickerNative, NcDialog } from '@nextcloud/vue'
import {
	buildMembershipPayload,
	ensureRelationType,
} from '../components/tabs/useRelationStore.js'
import { startOfDay } from '../utils/bodyMembership.js'
import { membershipTerm } from '../utils/stepDownSchedule.js'

export default {
	name: 'MemberTermDialog',
	components: { NcButton, NcDateTimePickerNative, NcDialog },
	props: {
		/** The joined membership row (body member) whose term is edited. */
		member: { type: Object, required: true },
	},

	emits: ['close', 'saved'],
	data() {
		return {
			startDate: this.member.startDate
				? new Date(this.member.startDate)
				: null,

			endDate: this.member.endDate ? new Date(this.member.endDate) : null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added */
		memberName() {
			return this.member.displayName || this.member.name || this.member.id
		},
	},

	methods: {
		/**
		 * Save the term onto the Membership, keeping what it already says.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added
		 */
		async save() {
			const term = membershipTerm(
				startOfDay(this.startDate),
				startOfDay(this.endDate),
			)
			if (!term.valid) {
				this.error = this.t(
					'decidiq',
					'The term cannot end before it begins.',
				)
				return
			}
			this.saving = true
			this.error = ''
			try {
				await ensureRelationType('membership').saveObject(
					'membership',
					buildMembershipPayload({
						id: this.member.id,
						personId: this.member.person,
						governanceBodyId: this.member.governanceBody,
						role: this.member.role,
						party: this.member.party,
						votingWeight: this.member.votingWeight,
						faction: this.member.faction || '',
						startDate: term.startDate,
						endDate: term.endDate,
					}),
				)
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to change the term.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.member-term__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.member-term__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
