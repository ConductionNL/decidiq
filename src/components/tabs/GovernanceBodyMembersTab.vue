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
	<div class="decidiq-tab decidiq-tab--members" data-testid="body-members-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Members') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<div class="decidiq-tab__actions">
				<NcCheckboxRadioSwitch
					v-model="showPast"
					type="switch"
					data-testid="body-members-past">
					{{ t('decidiq', 'Past members') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="secondary"
					data-testid="body-contact-details"
					:aria-label="t('decidiq', 'Contact details of this body')"
					@click="bodyContactOpen = true">
					{{ t('decidiq', 'Contact details') }}
				</NcButton>
				<NcActions :aria-label="t('decidiq', 'Import members')">
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
						{{ t('decidiq', 'Import from Nextcloud group') }}
					</NcActionButton>
					<NcActionButton
						data-testid="body-members-import-csv"
						closeAfterClick
						@click="csvImportOpen = true">
						<template #icon>
							<FileDelimited :size="20" />
						</template>
						{{ t('decidiq', 'Import from CSV') }}
					</NcActionButton>
				</NcActions>
				<NcButton
					variant="primary"
					data-testid="body-members-add"
					:aria-label="t('decidiq', 'Add member')"
					@click="addDialogOpen = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidiq', 'Add member') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load members')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No members linked to this body yet.')"
			:loadingText="t('decidiq', 'Loading members…')">
			<template #column-displayName="{ row, value }">
				<router-link
					v-if="row.person"
					:to="{ path: profilePath(row.person) }">
					{{ value }}
				</router-link>
				<span v-else>{{ value }}</span>
			</template>
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

		<ContactDetailsDialog
			v-if="contactTarget"
			:personId="contactTarget.person"
			:name="contactTarget.displayName"
			@saved="refresh"
			@close="contactTarget = null" />

		<ContactDetailsDialog
			v-if="bodyContactOpen"
			:bodyId="String(objectId)"
			@close="bodyContactOpen = false" />

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget"
			nameField="displayName"
			:dialogTitle="t('decidiq', 'Remove member')"
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
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import AccountEdit from 'vue-material-design-icons/AccountEdit.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import CardAccountDetailsOutline from 'vue-material-design-icons/CardAccountDetailsOutline.vue'
import FileDelimited from 'vue-material-design-icons/FileDelimited.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContactDetailsDialog from '../../modals/ContactDetailsDialog.vue'
import MemberAddDialog from '../../modals/MemberAddDialog.vue'
import MemberCsvImportDialog from '../../modals/MemberCsvImportDialog.vue'
import MemberGroupImportDialog from '../../modals/MemberGroupImportDialog.vue'
import MemberRoleDialog from '../../modals/MemberRoleDialog.vue'
import {
	contactSummary,
	factionsOf,
	memberRowsFor,
} from '../../utils/bodyMembership.js'
import { profilePath } from '../../utils/memberProfile.js'
import { buildMembershipPayload, ensureRelationType } from './useRelationStore.js'

export default {
	name: 'GovernanceBodyMembersTab',
	components: {
		AccountGroup,
		AccountMultiplePlus,
		CnDataTable,
		CnDeleteDialog,
		CnNoteCard,
		CnRowActions,
		ContactDetailsDialog,
		FileDelimited,
		MemberAddDialog,
		MemberCsvImportDialog,
		MemberGroupImportDialog,
		MemberRoleDialog,
		NcActionButton,
		NcActions,
		NcButton,
		NcCheckboxRadioSwitch,
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
			// bodies-membership-terms-contacts-and-factions
			showPast: false,
			contactTarget: null,
			bodyContactOpen: false,
		}
	},

	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		columns() {
			const columns = [
				{ key: 'displayName', label: this.t('decidiq', 'Name') },
				{ key: 'role', label: this.t('decidiq', 'Role') },
				{ key: 'factionName', label: this.t('decidiq', 'Faction') },
				{ key: 'party', label: this.t('decidiq', 'Party') },
				{ key: 'email', label: this.t('decidiq', 'Email') },
				{ key: 'phone', label: this.t('decidiq', 'Phone') },
				{ key: 'fromLabel', label: this.t('decidiq', 'From') },
			]
			if (this.showPast) {
				columns.push({ key: 'toLabel', label: this.t('decidiq', 'To') })
			}
			return columns
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidiq', 'Change role'),
					icon: AccountEdit,
					handler: (row) => {
						this.roleTarget = { ...row }
					},
				},
				{
					label: this.t('decidiq', 'Contact details'),
					icon: CardAccountDetailsOutline,
					handler: (row) => {
						this.contactTarget = { ...row }
					},
				},
				{
					label: this.t('decidiq', 'Remove from body'),
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
		/** @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when */
		showPast() {
			this.refresh()
		},

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
		 * The profile route of a member (REQ-MPR-003).
		 *
		 * @param {string} personId The person id
		 * @return {string}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile
		 */
		profilePath(personId) {
			return profilePath(personId)
		},

		/**
		 * Load every active Membership for this body and join each to its
		 * Person for the displayed name (spec.md "Members tab lists active
		 * memberships, not Participant rows").
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const membershipStore = ensureRelationType('membership')
				const personStore = ensureRelationType('person')
				// The body's own memberships, and on a faction page the
				// council memberships that name this faction.
				const [own, byFaction, factions] = await Promise.all([
					membershipStore.fetchCollection('membership', {
						governanceBody: this.objectId,
						_limit: 100,
					}),
					membershipStore.fetchCollection('membership', {
						faction: this.objectId,
						_limit: 100,
					}),
					ensureRelationType('governance-body').fetchCollection(
						'governance-body',
						{
							parentBody: this.objectId,
							bodyType: 'faction',
							_limit: 100,
						},
					),
				])
				const memberships = [
					...new Map(
						[...(own || []), ...(byFaction || [])].map((m) => [m.id, m]),
					).values(),
				]
				const personIds = [
					...new Set(memberships.map((m) => m.person).filter(Boolean)),
				]
				const [persons, contacts] = await Promise.all([
					Promise.all(
						personIds.map((id) => personStore.fetchObject('person', id)),
					),
					Promise.all(
						personIds.map((id) =>
							ensureRelationType('contact-detail')
								.fetchCollection('contact-detail', {
									person: id,
									_limit: 20,
								})
								.catch(() => []),
						),
					),
				])
				const personsById = {}
				const contactsById = {}
				personIds.forEach((id, i) => {
					personsById[id] = persons[i]
					contactsById[id] = contactSummary(contacts[i])
				})
				const factionNames = Object.fromEntries(
					factionsOf(factions, this.objectId).map((f) => [f.id, f.label]),
				)
				this.rows = memberRowsFor(memberships, personsById, {
					past: this.showPast,
				}).map((row) => ({
					...row,
					email: contactsById[row.person]?.email || row.email,
					phone: contactsById[row.person]?.phone || '',
					factionName: factionNames[row.faction] || '',
					fromLabel: this.dateLabel(row.startDate),
					toLabel: this.dateLabel(row.endDate),
				}))
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load members.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A membership date as the user's short date.
		 *
		 * @param {?string} value An ISO date-time.
		 *
		 * @return {string} The date, or '' when there is none.
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when
		 */
		dateLabel(value) {
			if (!value) return ''
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},

		/**
		 * "Remove from body": sets the Membership's endDate to today
		 * (Popolo departure semantics) rather than deleting the row or
		 * nulling a governanceBody pointer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when
		 */
		async confirmRemove() {
			const store = ensureRelationType('membership')
			const target = this.removeTarget
			try {
				// Keep what the membership already says (start date,
				// faction, party): the save replaces the object.
				await store.saveObject('membership', {
					...buildMembershipPayload({
						id: target.id,
						personId: target.person,
						governanceBodyId: target.governanceBody,
						role: target.role,
						party: target.party,
						votingWeight: target.votingWeight ?? null,
						startDate: target.startDate || '',
						faction: target.faction || '',
					}),
					endDate: new Date().toISOString(),
				})
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Remove failed.'),
				})
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
