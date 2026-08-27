<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — import members from CSV (ADR-004 modal isolation).

 The CSV (header: name,email,role) is parsed and validated entirely
 client-side (src/utils/memberImport.js, capped at MAX_IMPORT_ROWS);
 only the email→Nextcloud-account matching round-trips to the
 admin-gated /api/member-import/match endpoint (which mirrors the row
 cap server-side). model-debt-cleanup-code: valid, non-duplicate rows
 each become a Person (matched by email against an existing Person, else
 created) + Membership pair through the OpenRegister object API, not a
 Participant; rows without a matching NC account are still imported but
 flagged for manual linking, unchanged.

 @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Import from CSV')"
		size="large"
		data-testid="member-csv-import-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidiq',
						'Upload a CSV file with the columns: name, email, role.',
					)
				}}
			</p>
			<input
				type="file"
				accept=".csv,text/csv"
				:aria-label="t('decidiq', 'CSV file')"
				data-testid="csv-import-file"
				@change="onFile" />

			<table
				v-if="preview.length"
				class="csv-import__table"
				data-testid="csv-import-preview">
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Line') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Name') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Email') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Role') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Account') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Status') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in preview" :key="row.line">
						<td>{{ row.line }}</td>
						<td>{{ row.name }}</td>
						<td>{{ row.email }}</td>
						<td>{{ row.role }}</td>
						<td>
							<span v-if="row.matchedUid">{{ row.matchedUid }}</span>
							<span
								v-else-if="row.status === 'ok'"
								class="csv-import__unmatched">
								{{
									t(
										'decidiq',
										'No account — manual linking needed',
									)
								}}
							</span>
						</td>
						<td>
							<span :class="'csv-import__status--' + row.status">{{
								statusLabel(row)
							}}</span>
						</td>
					</tr>
				</tbody>
			</table>

			<p v-if="error" class="csv-import__error" data-testid="csv-import-error">
				{{ error }}
			</p>
			<p
				v-if="doneMessage"
				class="csv-import__done"
				data-testid="csv-import-done">
				{{ doneMessage }}
			</p>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="importing || importableCount === 0"
				data-testid="csv-import-submit"
				@click="runImport">
				{{
					importing
						? t('decidiq', 'Importing…')
						: t('decidiq', 'Import {count} members', {
								count: importableCount,
							})
				}}
			</NcButton>
			<NcButton data-testid="csv-import-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'
import {
	buildMembershipPayload,
	ensureRelationType,
	resolveOrCreatePerson,
} from '../components/tabs/useRelationStore.js'
import {
	MAX_IMPORT_ROWS,
	parseMemberCsv,
	validateMemberRows,
} from '../utils/memberImport.js'

export default {
	name: 'MemberCsvImportDialog',
	components: { NcButton, NcDialog },
	props: {
		/** OR object id of the governance body to import into. */
		bodyId: { type: [String, Number], required: true },
		/** Current members of the body (duplicate detection). */
		existingMembers: { type: Array, default: () => [] },
	},

	emits: ['close', 'imported'],
	data() {
		return {
			preview: [],
			importing: false,
			error: '',
			doneMessage: '',
		}
	},

	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		importableCount() {
			return this.preview.filter((row) => row.status === 'ok').length
		},
	},

	methods: {
		/**
		 * Translated status text for one previewed CSV row.
		 *
		 * @param {object} row A validated preview row (status + reason).
		 * @return {string} The translated status label.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		statusLabel(row) {
			if (row.status === 'ok') {
				return this.t('decidiq', 'Will be imported')
			}
			if (row.status === 'duplicate') {
				return row.reason === 'duplicate-in-file'
					? this.t('decidiq', 'Duplicate row — skipped')
					: this.t('decidiq', 'Already a member — skipped')
			}
			const reasons = {
				'missing-name': this.t('decidiq', 'Missing name'),
				'invalid-email': this.t('decidiq', 'Invalid email address'),
				'invalid-role': this.t('decidiq', 'Unknown role'),
			}
			return reasons[row.reason] || this.t('decidiq', 'Invalid row')
		},

		/**
		 * Parse, validate and account-match a newly chosen CSV file.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async onFile(event) {
			this.error = ''
			this.doneMessage = ''
			this.preview = []
			const file = event.target.files?.[0]
			if (!file) {
				return
			}
			const text = await file.text()
			const { rows, error } = parseMemberCsv(text)
			if (error === 'header') {
				this.error = this.t(
					'decidiq',
					'The CSV must have a header row with name and email columns.',
				)
				return
			}
			if (error === 'too-many-rows') {
				this.error = this.t(
					'decidiq',
					'At most {max} rows can be imported at once.',
					{ max: MAX_IMPORT_ROWS },
				)
				return
			}
			if (error === 'empty' || rows.length === 0) {
				this.error = this.t('decidiq', 'The CSV file contains no rows.')
				return
			}
			const validated = validateMemberRows(rows, this.existingMembers)
			this.preview = await this.matchAccounts(validated)
		},

		/**
		 * Resolve each row's email to a Nextcloud account, best-effort.
		 *
		 * @param {Array<object>} rows Validated preview rows.
		 * @return {Promise<Array<object>>} The rows, each with a matchedUid.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async matchAccounts(rows) {
			const emails = rows.filter((r) => r.status === 'ok').map((r) => r.email)
			if (emails.length === 0) {
				return rows.map((r) => ({ ...r, matchedUid: '' }))
			}
			try {
				const response = await fetch(
					generateUrl('/apps/decidiq/api/member-import/match'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: getRequestToken(),
						},
						body: JSON.stringify({ emails }),
					},
				)
				if (!response.ok) {
					throw new Error(
						this.t(
							'decidiq',
							'Account matching failed (admin access required).',
						),
					)
				}
				const data = await response.json()
				const matches = data?.matches || {}
				return rows.map((r) => ({
					...r,
					matchedUid: matches[r.email]?.uid || '',
				}))
			} catch (e) {
				// Matching is best-effort: rows import unlinked when it fails.
				this.error =
					e?.message || this.t('decidiq', 'Account matching failed.')
				return rows.map((r) => ({ ...r, matchedUid: '' }))
			}
		},

		/**
		 * Import the previewed CSV rows: each becomes a Person (matched by
		 * email, else created) + Membership pair.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async runImport() {
			this.importing = true
			this.error = ''
			try {
				const personStore = ensureRelationType('person')
				const membershipStore = ensureRelationType('membership')
				const rows = this.preview.filter((row) => row.status === 'ok')
				for (const row of rows) {
					// Sequential on purpose: predictable ordering + no API hammering.
					const person = await resolveOrCreatePerson(personStore, {
						name: row.name,
						email: row.email,
						nextcloudUserId: row.matchedUid || '',
					})
					if (!person?.id) {
						continue
					}
					await membershipStore.saveObject(
						'membership',
						buildMembershipPayload({
							personId: person.id,
							governanceBodyId: this.bodyId,
							role: row.role,
						}),
					)
				}
				this.doneMessage = this.t('decidiq', '{count} members imported.', {
					count: rows.length,
				})
				this.$emit('imported')
				// Mark imported rows so a second click cannot double-import.
				this.preview = this.preview.map((row) =>
					row.status === 'ok'
						? { ...row, status: 'duplicate', reason: 'already-member' }
						: row,
				)
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Import failed.')
			} finally {
				this.importing = false
			}
		},
	},
}
</script>

<style scoped>
.csv-import__table {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.csv-import__table th,
.csv-import__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.csv-import__status--duplicate,
.csv-import__unmatched {
	color: var(--color-text-maxcontrast);
}

.csv-import__status--invalid {
	color: var(--color-error);
}

.csv-import__error {
	color: var(--color-error);
	margin: 8px 0 0;
}

.csv-import__done {
	color: var(--color-success);
	margin: 8px 0 0;
}
</style>
