<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Profile widget: the person's votes in closed rounds that were not secret,
 newest first, with date, decision, choice, result and the party at the
 time. Read from GET /api/people/{personId}/voting-record, which never lists
 a secret round or an anonymised vote.

 @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
-->
<template>
	<div class="decidiq-tab decidiq-tab--voting-record" data-testid="person-voting-record">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load the voting record')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			v-else
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No recorded votes.')"
			:loadingText="t('decidiq', 'Loading the voting record…')">
			<template #column-date="{ value }">
				{{ dateLabel(value) }}
			</template>
			<template #column-decision="{ row, value }">
				<router-link v-if="row.decisionId" :to="{ path: `/decisions/${row.decisionId}` }">
					{{ value }}
				</router-link>
				<span v-else>{{ t('decidiq', 'Vote without a linked decision') }}</span>
			</template>
			<template #column-choice="{ value }">
				{{ choiceLabel(value) }}
			</template>
			<template #column-result="{ value }">
				{{ resultLabel(value) }}
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { recordRows, votingRecordUrl } from '../../utils/memberProfile.js'

export default {
	name: 'PersonVotingRecordTab',
	components: { CnDataTable, CnNoteCard },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record */
		columns() {
			return [
				{ key: 'date', label: this.t('decidiq', 'Date') },
				{ key: 'decision', label: this.t('decidiq', 'Decision') },
				{ key: 'choice', label: this.t('decidiq', 'Vote') },
				{ key: 'result', label: this.t('decidiq', 'Result') },
				{ key: 'party', label: this.t('decidiq', 'Party') },
			]
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load the person's voting record.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const response = await fetch(generateUrl(votingRecordUrl(String(this.objectId))), {
					headers: { requesttoken: window.OC?.requestToken },
				})
				if (!response.ok) {
					throw new Error(this.t('decidiq', 'The voting record could not be read.'))
				}
				this.rows = recordRows(await response.json())
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The voting record could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A date as the user's short date.
		 *
		 * @param {?string} value An ISO date-time
		 * @return {string}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
		 */
		dateLabel(value) {
			if (!value) return ''
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},

		/**
		 * The member's choice in words.
		 *
		 * @param {string} value for, against or abstain
		 * @return {string}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
		 */
		choiceLabel(value) {
			const labels = {
				for: this.t('decidiq', 'For'),
				against: this.t('decidiq', 'Against'),
				abstain: this.t('decidiq', 'Abstain'),
			}
			return labels[value] || value
		},

		/**
		 * The round's result in words.
		 *
		 * @param {string} value adopted, rejected, tied or invalid
		 * @return {string}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
		 */
		resultLabel(value) {
			const labels = {
				adopted: this.t('decidiq', 'Adopted'),
				rejected: this.t('decidiq', 'Rejected'),
				tied: this.t('decidiq', 'Tied'),
				invalid: this.t('decidiq', 'Invalid'),
			}
			return labels[value] || value
		},
	},
}
</script>
