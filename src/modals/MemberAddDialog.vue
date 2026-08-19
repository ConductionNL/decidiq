<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — add-member dialog (ADR-004 modal isolation).

 model-debt-cleanup-code: this used to pick an already-existing, unlinked
 Participant from a list. Participant no longer models body membership —
 a Person can hold multiple Memberships across bodies, so there is no
 equivalent "unassigned participant" pool. This dialog now creates a
 Membership linking a Person (matched by email, or created) to the body,
 mirroring the crosswalk resolver's own match-or-create step
 (design.md Decision 1).

 @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Add member')"
		size="normal"
		data-testid="member-add-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="member-add__form">
				<NcTextField
					v-model="name"
					data-testid="member-add-name"
					:label="t('decidesk', 'Name')"
					:placeholder="t('decidesk', 'e.g. Roos de Vries')" />
				<NcTextField
					v-model="email"
					type="email"
					data-testid="member-add-email"
					:label="t('decidesk', 'Email')"
					:placeholder="t('decidesk', 'name@example.org')" />
				<p class="member-add__hint">
					{{
						t(
							'decidesk',
							'An existing person with this email will be reused; otherwise a new one is created.',
						)
					}}
				</p>
				<NcSelect
					v-model="selectedRole"
					:inputLabel="t('decidesk', 'Role')"
					:options="roleOptions"
					label="label"
					:clearable="false"
					data-testid="member-add-role" />
				<NcTextField
					v-model="party"
					data-testid="member-add-party"
					:label="t('decidesk', 'Party')"
					:placeholder="t('decidesk', 'e.g. GroenLinks')" />
				<p
					v-if="error"
					class="member-add__error"
					data-testid="member-add-error">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="linking || !name.trim()"
				data-testid="member-add-submit"
				@click="link">
				{{
					linking ? t('decidesk', 'Adding…') : t('decidesk', 'Add member')
				}}
			</NcButton>
			<NcButton data-testid="member-add-cancel" @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	buildMembershipPayload,
	ensureRelationType,
	resolveOrCreatePerson,
} from '../components/tabs/useRelationStore.js'
import { DEFAULT_ROLE, MEMBER_ROLES } from '../utils/memberImport.js'

export default {
	name: 'MemberAddDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	props: {
		/** OR object id of the governance body the new member is linked to. */
		bodyId: { type: [String, Number], required: true },
	},

	emits: ['close', 'linked'],
	data() {
		return {
			name: '',
			email: '',
			party: '',
			selectedRole: null,
			linking: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md */
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

	/** @spec exclude lifecycle hook; only seeds the role select's default */
	created() {
		this.selectedRole =
			this.roleOptions.find((o) => o.id === DEFAULT_ROLE) || null
	},

	methods: {
		/**
		 * Resolve (match by email, else create) a Person for the entered
		 * identity fields, then create a Membership linking it to this body
		 * (design.md Decision 1's match-or-create step, client-side).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async link() {
			const trimmedName = this.name.trim()
			if (!trimmedName) {
				return
			}
			this.linking = true
			this.error = ''
			try {
				const personStore = ensureRelationType('person')
				const membershipStore = ensureRelationType('membership')
				const person = await resolveOrCreatePerson(personStore, {
					name: trimmedName,
					email: this.email.trim(),
				})
				const personId = person?.id
				if (!personId) {
					throw new Error(
						this.t('decidesk', 'Could not create or match a person.'),
					)
				}
				await membershipStore.saveObject(
					'membership',
					buildMembershipPayload({
						personId,
						governanceBodyId: this.bodyId,
						role: this.selectedRole?.id || DEFAULT_ROLE,
						party: this.party.trim(),
					}),
				)
				this.$emit('linked')
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to add member.')
			} finally {
				this.linking = false
			}
		},
	},
}
</script>

<style scoped>
.member-add__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.member-add__hint {
	color: var(--color-text-maxcontrast);
	margin: -4px 0 0;
	font-size: 0.85em;
}

.member-add__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
