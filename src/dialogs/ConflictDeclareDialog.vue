<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: a member declares a conflict of interest on a motion or agenda item.

 The member picks the kind of interest, gives the reason and may keep herself
 out of the vote at once. The parent posts the declaration on @submit
 (modal isolation, ADR-004).

 Below the form the member sees her own registered other positions that have
 not ended, for reference; a position whose organisation or function shares a
 word with the subject's title is marked (interests-and-integrity, REQ-INT-007).
 That block is display only: it never declares, warns or blocks anything.

 @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Declare a conflict of interest')"
		data-testid="conflict-declare-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-conflict-form">
				<NcSelect
					v-model="type"
					:options="typeOptions"
					:inputLabel="t('decidiq', 'Kind of interest')"
					:clearable="false"
					data-testid="conflict-declare-type" />
				<NcTextArea
					v-model="description"
					data-testid="conflict-declare-reason"
					:label="t('decidiq', 'Reason')"
					:placeholder="
						t('decidiq', 'For example: I own land in the plan area')
					"
					resize="vertical" />
				<NcCheckboxRadioSwitch
					v-model="recuse"
					data-testid="conflict-declare-recuse">
					{{ t('decidiq', 'I will not vote on this') }}
				</NcCheckboxRadioSwitch>
				<p v-if="error" class="decidiq-error" role="alert">
					{{ error }}
				</p>
				<section
					v-if="positionRows.length"
					class="decidiq-conflict-positions"
					data-testid="conflict-declare-positions">
					<h3 class="decidiq-conflict-positions__title">
						{{ t('decidiq', 'Your registered other positions') }}
					</h3>
					<ul class="decidiq-conflict-positions__list">
						<li
							v-for="row in positionRows"
							:key="row.id"
							:class="{
								'decidiq-conflict-positions__item--match':
									row.matches,
							}"
							data-testid="conflict-declare-position">
							<span>{{ row.organisation }}</span>
							<span
								v-if="row.role"
								class="decidiq-conflict-positions__role">
								{{ row.role }}
							</span>
							<span
								v-if="row.matches"
								class="decidiq-conflict-positions__match"
								data-testid="conflict-declare-position-match">
								{{ t('decidiq', 'Matches the subject') }}
							</span>
						</li>
					</ul>
				</section>
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="conflict-declare-submit"
				:disabled="busy || !description.trim()"
				@click="submit">
				{{ t('decidiq', 'Declare') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextArea,
} from '@nextcloud/vue'
import { ancillaryContextRows } from '../utils/ancillaryContext.js'

export default {
	name: 'ConflictDeclareDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextArea,
	},

	props: {
		/** Whether the parent is posting the declaration */
		busy: { type: Boolean, default: false },
		/** The server's refusal, shown in the dialog */
		error: { type: String, default: '' },
		/** The member's own ancillary positions, for reference */
		positions: { type: Array, default: () => [] },
		/** The title of the agenda item or motion declared on */
		subjectTitle: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		const typeOptions = [
			{
				id: 'financial-interest',
				label: this.t('decidiq', 'Financial interest'),
			},
			{
				id: 'personal-relationship',
				label: this.t('decidiq', 'Personal relationship'),
			},
			{
				id: 'competing-business',
				label: this.t('decidiq', 'Competing business'),
			},
			{
				id: 'prior-involvement',
				label: this.t('decidiq', 'Earlier involvement'),
			},
		]
		return {
			typeOptions,
			type: typeOptions[0],
			description: '',
			recuse: true,
		}
	},

	computed: {
		/** @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces */
		positionRows() {
			return ancillaryContextRows(this.positions, this.subjectTitle)
		},
	},

	methods: {
		/** @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page */
		submit() {
			this.$emit('submit', {
				declarationType: this.type?.id || 'financial-interest',
				description: this.description,
				recuseFromVote: this.recuse,
			})
		},
	},
}
</script>

<style scoped>
.decidiq-conflict-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-error {
	color: var(--color-error-text);
	margin: 0;
}

.decidiq-conflict-positions__title {
	font-size: 1em;
	font-weight: bold;
	margin: 0 0 var(--default-grid-baseline);
}

.decidiq-conflict-positions__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidiq-conflict-positions__list li {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
}

.decidiq-conflict-positions__item--match {
	background-color: var(--color-warning-hover, var(--color-background-hover));
}

.decidiq-conflict-positions__role {
	color: var(--color-text-maxcontrast);
}

.decidiq-conflict-positions__match {
	font-weight: bold;
	color: var(--color-warning-text);
}
</style>
