<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Record a member's competence on their seat in the body, at a level
 (bodies-board-composition-skills-and-diversity, ADR-004 modal isolation).
 A member records their own; a signatory of the body records one for any
 member. The route refuses anyone else, and a recorded competence stays
 unconfirmed until a signatory confirms it.

 @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Record competence')"
		size="normal"
		data-testid="member-competence-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="member-competence__form">
				<NcSelect
					v-model="seat"
					:inputLabel="t('decidiq', 'Member')"
					:options="seatOptions"
					label="label"
					:clearable="false"
					data-testid="member-competence-seat" />
				<NcSelect
					v-model="competence"
					:inputLabel="t('decidiq', 'Competence')"
					:options="competenceOptions"
					label="label"
					:clearable="false"
					data-testid="member-competence-competence" />
				<NcSelect
					v-model="level"
					:inputLabel="t('decidiq', 'Level')"
					:options="levelOptions"
					label="label"
					:clearable="false"
					data-testid="member-competence-level" />
				<NcTextField
					v-model="note"
					:label="t('decidiq', 'Where it comes from (optional)')"
					data-testid="member-competence-note" />
				<p v-if="error" class="member-competence__error" role="alert">
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
				:disabled="saving || !seat || !competence || !level"
				data-testid="member-competence-save"
				@click="save">
				{{ t('decidiq', 'Record competence') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { competencePath, competenceRequest } from '../utils/competenceApi.js'

export default {
	name: 'MemberCompetenceModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		/** The matrix rows: {seat, name}. */
		seats: { type: Array, default: () => [] },
		/** The body's active competences: {id, name}. */
		competences: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			seat: null,
			competence: null,
			level: null,
			note: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed */
		seatOptions() {
			return this.seats.map((row) => ({ id: row.seat, label: row.name }))
		},

		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed */
		competenceOptions() {
			return this.competences.map((c) => ({ id: c.id, label: c.name }))
		},

		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed */
		levelOptions() {
			return [
				{ id: 'basic', label: this.t('decidiq', 'basic') },
				{ id: 'experienced', label: this.t('decidiq', 'experienced') },
				{ id: 'expert', label: this.t('decidiq', 'expert') },
			]
		},
	},

	methods: {
		/**
		 * Record the competence through the guarded route.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				await competenceRequest('POST', competencePath('member-competences'), {
					membership: this.seat.id,
					competence: this.competence.id,
					level: this.level.id,
					note: this.note.trim(),
				})
				this.$emit('saved')
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The competence could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.member-competence__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.member-competence__error {
	color: var(--color-error-text);
}
</style>
