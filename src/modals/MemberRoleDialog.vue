<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — change-member-role dialog (ADR-004 modal isolation).

 Assigns one of the Membership role-enum values to a body member and
 persists via the shared OR object store (OpenRegister enforces
 per-object RBAC server-side). model-debt-cleanup-code: the role is
 written onto the Membership object, not the deprecated Participant
 shim. Role → permission mapping (chair starts votes / manages agenda,
 secretary takes minutes, member votes) is enforced by the backend
 services that consume the role field.

 @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Change role')"
		size="small"
		data-testid="member-role-dialog"
		@closing="$emit('close')">
		<template #default>
			<p data-testid="member-role-member">
				{{ t('decidesk', 'Assign a role to {name}.', { name: memberName }) }}
			</p>
			<NcSelect
				v-model="selectedRole"
				:inputLabel="t('decidesk', 'Role')"
				:options="roleOptions"
				label="label"
				:clearable="false"
				data-testid="member-role-select" />
			<p
				v-if="error"
				class="member-role__error"
				data-testid="member-role-error">
				{{ error }}
			</p>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="saving || !selectedRole"
				data-testid="member-role-save"
				@click="save">
				{{ saving ? t('decidesk', 'Saving…') : t('decidesk', 'Save') }}
			</NcButton>
			<NcButton data-testid="member-role-cancel" @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import {
	buildMembershipPayload,
	ensureRelationType,
} from '../components/tabs/useRelationStore.js'
import { MEMBER_ROLES } from '../utils/memberImport.js'

export default {
	name: 'MemberRoleDialog',
	components: { NcButton, NcDialog, NcSelect },
	props: {
		/** The joined membership row (body member) whose role is being changed. */
		member: { type: Object, required: true },
	},

	emits: ['close', 'saved'],
	data() {
		return {
			selectedRole: null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		memberName() {
			return this.member.displayName || this.member.name || this.member.id
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		roleOptions() {
			const labels = {
				chair: this.t('decidesk', 'Chair'),
				'vice-chair': this.t('decidesk', 'Vice-chair'),
				secretary: this.t('decidesk', 'Secretary'),
				treasurer: this.t('decidesk', 'Treasurer'),
				member: this.t('decidesk', 'Member'),
				observer: this.t('decidesk', 'Observer'),
				guest: this.t('decidesk', 'Guest'),
			}
			return MEMBER_ROLES.map((role) => ({
				id: role,
				label: labels[role] || role,
			}))
		},
	},

	/** @spec exclude lifecycle hook; only seeds the select with the current role */
	created() {
		this.selectedRole =
			this.roleOptions.find((o) => o.id === this.member.role) || null
	},

	methods: {
		/**
		 * Persist the selected role onto the Membership (not a Participant).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async save() {
			if (!this.selectedRole) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const store = ensureRelationType('membership')
				await store.saveObject(
					'membership',
					buildMembershipPayload({
						id: this.member.id,
						personId: this.member.person,
						governanceBodyId: this.member.governanceBody,
						role: this.selectedRole.id,
						party: this.member.party,
						votingWeight: this.member.votingWeight,
					}),
				)
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to change role.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.member-role__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
