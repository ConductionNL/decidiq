<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: members of a Governance Body.

 Members are Popolo Membership objects (model-debt-cleanup-code) linking a
 Person to this governance body via the Membership's `governanceBody`
 field. The list is every active Membership (no `endDate`) for this body,
 joined to each Membership's Person for the displayed name. Adding a
 member creates/matches a Person and creates a Membership; "Remove from
 body" sets the Membership's `endDate` to today (Popolo departure
 semantics) rather than deleting or nulling a pointer; the role action
 writes onto the Membership. New members can be imported from a Nextcloud
 group or a CSV file. The deprecated flat `Participant` schema is no
 longer read or written by this tab (see design.md's own status note: the
 Members tab was root-caused once before, on the same deprecated schema).

 All dialogs live in src/modals/ (ADR-004 modal isolation).

 @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--members" data-testid="body-members-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Members') }}
				<span v-if="!loading" class="decidesk-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<div class="decidesk-tab__actions">
				<NcActions :aria-label="t('decidesk', 'Import members')">
					<template #icon>
						<AccountMultiplePlus :size="20" />
					</template>
					<NcActionButton
						data-testid="body-members-import-group"
						closeAfterClick
						@click="groupImportOpen = true">
						<template #icon>
							<AccountGroup :size="20" />
						</template>
						{{ t('decidesk', 'Import from Nextcloud group') }}
					</NcActionButton>
					<NcActionButton
						data-testid="body-members-import-csv"
						closeAfterClick
						@click="csvImportOpen = true">
						<template #icon>
							<FileDelimited :size="20" />
						</template>
						{{ t('decidesk', 'Import from CSV') }}
					</NcActionButton>
				</NcActions>
				<NcButton
					variant="primary"
					data-testid="body-members-add"
					:aria-label="t('decidesk', 'Add member')"
					@click="addDialogOpen = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidesk', 'Add member') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load members')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidesk', 'No members linked to this body yet.')"
			:loadingText="t('decidesk', 'Loading members…')">
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<MemberAddDialog
			v-if="addDialogOpen"
			:bodyId="objectId"
			@linked="refresh"
			@close="addDialogOpen = false" />

		<MemberRoleDialog
			v-if="roleTarget"
			:member="roleTarget"
			@saved="refresh"
			@close="roleTarget = null" />

		<MemberGroupImportDialog
			v-if="groupImportOpen"
			:bodyId="objectId"
			:existingMembers="rows"
			@imported="refresh"
			@close="groupImportOpen = false" />

		<MemberCsvImportDialog
			v-if="csvImportOpen"
			:bodyId="objectId"
			:existingMembers="rows"
			@imported="refresh"
			@close="csvImportOpen = false" />

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget"
			nameField="displayName"
			:dialogTitle="t('decidesk', 'Remove member')"
			@confirm="confirmRemove"
			@close="removeTarget = null" />
	</div>
</template>

<script>
import {
	CnDataTable,
	CnDeleteDialog,
	CnNoteCard,
	CnRowActions,
} from '@conduction/nextcloud-vue'
import { NcActionButton, NcActions, NcButton } from '@nextcloud/vue'
import AccountEdit from 'vue-material-design-icons/AccountEdit.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import FileDelimited from 'vue-material-design-icons/FileDelimited.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import MemberAddDialog from '../../modals/MemberAddDialog.vue'
import MemberCsvImportDialog from '../../modals/MemberCsvImportDialog.vue'
import MemberGroupImportDialog from '../../modals/MemberGroupImportDialog.vue'
import MemberRoleDialog from '../../modals/MemberRoleDialog.vue'
import {
	buildMemberRows,
	ensureRelationType,
	isActiveMembership,
} from './useRelationStore.js'

export default {
	name: 'GovernanceBodyMembersTab',
	components: {
		AccountGroup,
		AccountMultiplePlus,
		CnDataTable,
		CnDeleteDialog,
		CnNoteCard,
		CnRowActions,
		FileDelimited,
		MemberAddDialog,
		MemberCsvImportDialog,
		MemberGroupImportDialog,
		MemberRoleDialog,
		NcActionButton,
		NcActions,
		NcButton,
		Plus,
	},

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
			addDialogOpen: false,
			groupImportOpen: false,
			csvImportOpen: false,
			roleTarget: null,
			removeTarget: null,
		}
	},

	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		columns() {
			return [
				{ key: 'displayName', label: this.t('decidesk', 'Name') },
				{ key: 'role', label: this.t('decidesk', 'Role') },
				{ key: 'party', label: this.t('decidesk', 'Party') },
			]
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidesk', 'Change role'),
					icon: AccountEdit,
					handler: (row) => {
						this.roleTarget = { ...row }
					},
				},
				{
					label: this.t('decidesk', 'Remove from body'),
					icon: LinkOff,
					destructive: true,
					handler: (row) => {
						this.removeTarget = { ...row }
					},
				},
			]
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/admin-settings/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load every active Membership for this body and join each to its
		 * Person for the displayed name (spec.md "Members tab lists active
		 * memberships, not Participant rows").
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const membershipStore = ensureRelationType('membership')
				const personStore = ensureRelationType('person')
				const memberships = await membershipStore.fetchCollection(
					'membership',
					{ governanceBody: this.objectId, _limit: 100 },
				)
				const active = (memberships || []).filter(isActiveMembership)
				const personIds = [
					...new Set(active.map((m) => m.person).filter(Boolean)),
				]
				const persons = await Promise.all(
					personIds.map((id) => personStore.fetchObject('person', id)),
				)
				const personsById = {}
				personIds.forEach((id, i) => {
					personsById[id] = persons[i]
				})
				this.rows = buildMemberRows(active, personsById)
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load members.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * "Remove from body": sets the Membership's endDate to today
		 * (Popolo departure semantics) rather than deleting the row or
		 * nulling a governanceBody pointer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async confirmRemove() {
			const store = ensureRelationType('membership')
			const target = this.removeTarget
			try {
				await store.saveObject('membership', {
					id: target.id,
					person: target.person,
					governanceBody: target.governanceBody,
					role: target.role,
					endDate: new Date().toISOString(),
				})
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({
					error: e?.message || this.t('decidesk', 'Remove failed.'),
				})
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
