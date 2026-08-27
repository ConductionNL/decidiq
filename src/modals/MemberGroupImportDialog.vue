<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — import members from a Nextcloud group (ADR-004 modal
 isolation).

 Group + member listing comes from the admin-gated
 /apps/decidiq/api/member-import endpoints (AuthorizedAdminSetting).
 model-debt-cleanup-code: each imported user becomes a Person (matched by
 email against an existing Person, else created) + Membership pair, not a
 Participant, going through the OpenRegister object API via the shared
 store. Members already linked to the body (by NC uid or email) are
 flagged as duplicates and skipped.

 @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Import from Nextcloud group')"
		size="normal"
		data-testid="member-group-import-dialog"
		@closing="$emit('close')">
		<template #default>
			<NcSelect
				v-model="selectedGroup"
				:inputLabel="t('decidiq', 'Nextcloud group')"
				:options="groupOptions"
				label="label"
				:loading="loadingGroups"
				:clearable="false"
				data-testid="group-import-select" />

			<div v-if="loadingMembers" class="group-import__loading">
				{{ t('decidiq', 'Loading group members…') }}
			</div>

			<table
				v-else-if="preview.length"
				class="group-import__table"
				data-testid="group-import-preview">
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Name') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Email') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Status') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in preview" :key="row.uid">
						<td>{{ row.displayName }}</td>
						<td>{{ row.email }}</td>
						<td>
							<span v-if="row.duplicate" class="group-import__dup">
								{{ t('decidiq', 'Already a member — skipped') }}
							</span>
							<span v-else>{{
								t('decidiq', 'Will be imported')
							}}</span>
						</td>
					</tr>
				</tbody>
			</table>

			<p
				v-if="error"
				class="group-import__error"
				data-testid="group-import-error">
				{{ error }}
			</p>
			<p
				v-if="doneMessage"
				class="group-import__done"
				data-testid="group-import-done">
				{{ doneMessage }}
			</p>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="importing || importableCount === 0"
				data-testid="group-import-submit"
				@click="runImport">
				{{
					importing
						? t('decidiq', 'Importing…')
						: t('decidiq', 'Import {count} members', {
								count: importableCount,
							})
				}}
			</NcButton>
			<NcButton data-testid="group-import-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import {
	buildMembershipPayload,
	ensureRelationType,
	resolveOrCreatePerson,
} from '../components/tabs/useRelationStore.js'
import { DEFAULT_ROLE, markGroupDuplicates } from '../utils/memberImport.js'

export default {
	name: 'MemberGroupImportDialog',
	components: { NcButton, NcDialog, NcSelect },
	props: {
		/** OR object id of the governance body to import into. */
		bodyId: { type: [String, Number], required: true },
		/** Current members of the body (duplicate detection). */
		existingMembers: { type: Array, default: () => [] },
	},

	emits: ['close', 'imported'],
	data() {
		return {
			loadingGroups: false,
			loadingMembers: false,
			importing: false,
			groups: [],
			selectedGroup: null,
			preview: [],
			error: '',
			doneMessage: '',
		}
	},

	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		groupOptions() {
			return this.groups.map((g) => ({
				id: g.id,
				label: `${g.displayName} (${g.userCount})`,
			}))
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		importableCount() {
			return this.preview.filter((row) => !row.duplicate).length
		},
	},

	watch: {
		/**
		 * Load the chosen group's members, or clear the preview when cleared.
		 *
		 * @param {object|null} group The newly selected group option.
		 * @return {void}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		selectedGroup(group) {
			this.doneMessage = ''
			if (group) {
				this.loadMembers(group.id)
			} else {
				this.preview = []
			}
		},
	},

	/** @spec exclude lifecycle hook; only triggers the group list fetch */
	created() {
		this.loadGroups()
	},

	methods: {
		/** @spec openspec/specs/admin-settings/spec.md */
		async loadGroups() {
			this.loadingGroups = true
			this.error = ''
			try {
				const response = await fetch(
					generateUrl('/apps/decidiq/api/member-import/groups'),
					{
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (!response.ok) {
					throw new Error(
						this.t(
							'decidiq',
							'Could not load groups (admin access required).',
						),
					)
				}
				const data = await response.json()
				this.groups = data?.groups || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Could not load groups.')
			} finally {
				this.loadingGroups = false
			}
		},

		/**
		 * Fetch a group's members and flag those already in the body.
		 *
		 * @param {string} groupId The Nextcloud group id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async loadMembers(groupId) {
			this.loadingMembers = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/decidiq/api/member-import/groups/{groupId}/members',
					{ groupId },
				)
				const response = await fetch(url, {
					headers: { requesttoken: getRequestToken() },
				})
				if (!response.ok) {
					throw new Error(
						this.t('decidiq', 'Could not load group members.'),
					)
				}
				const data = await response.json()
				this.preview = markGroupDuplicates(
					data?.members || [],
					this.existingMembers,
				)
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Could not load group members.')
				this.preview = []
			} finally {
				this.loadingMembers = false
			}
		},

		/**
		 * Import the previewed group members: each becomes a Person
		 * (matched by email, else created) + Membership pair.
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
				const rows = this.preview.filter((row) => !row.duplicate)
				for (const row of rows) {
					// Sequential on purpose: predictable ordering + no API hammering.
					const person = await resolveOrCreatePerson(personStore, {
						name: row.displayName,
						email: row.email,
						nextcloudUserId: row.uid,
					})
					if (!person?.id) {
						continue
					}
					await membershipStore.saveObject(
						'membership',
						buildMembershipPayload({
							personId: person.id,
							governanceBodyId: this.bodyId,
							role: DEFAULT_ROLE,
						}),
					)
				}
				this.doneMessage = this.t('decidiq', '{count} members imported.', {
					count: rows.length,
				})
				this.$emit('imported')
				// Refresh duplicate flags so a second click cannot double-import.
				this.preview = this.preview.map((row) => ({
					...row,
					duplicate: true,
				}))
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
.group-import__table {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.group-import__table th,
.group-import__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.group-import__dup {
	color: var(--color-text-maxcontrast);
}

.group-import__error {
	color: var(--color-error);
	margin: 8px 0 0;
}

.group-import__done {
	color: var(--color-success);
	margin: 8px 0 0;
}

.group-import__loading {
	color: var(--color-text-maxcontrast);
	margin: 8px 0 0;
}
</style>
