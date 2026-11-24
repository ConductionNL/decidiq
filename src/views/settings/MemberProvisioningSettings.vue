<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: member provisioning. The administrator lists the
 Nextcloud groups a governance body may map for its members; onboarding adds
 a member only to listed groups and offboarding removes them only from listed
 groups. The admin group is never provisioned.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.
-->
<template>
	<div class="decidiq-member-provisioning" data-testid="member-provisioning-settings">
		<h3 class="decidiq-member-provisioning__title">
			{{ t('decidiq', 'Member provisioning') }}
		</h3>
		<p>
			{{ t('decidiq', 'The groups a governance body may assign to its members when they join, and take away when they leave. Separate groups with commas. The admin group is never assigned.') }}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'The setting was not saved')">
			{{ error }}
		</CnNoteCard>

		<NcTextField
			v-if="loaded"
			v-model="groups"
			:label="t('decidiq', 'Allowed groups')"
			:disabled="saving"
			data-testid="member-provisioning-settings-groups" />
		<NcButton
			v-if="loaded"
			:disabled="saving"
			data-testid="member-provisioning-settings-save"
			@click="save">
			{{ t('decidiq', 'Save') }}
		</NcButton>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import {
	provisioningGroups,
	provisioningGroupsPayload,
} from '../../utils/memberProvisioningGroups.js'

export default {
	name: 'MemberProvisioningSettings',
	components: { CnNoteCard, NcButton, NcTextField },
	data() {
		return {
			loaded: false,
			saving: false,
			groups: '',
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the allowed groups from the app settings.
		 *
		 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/decidiq/api/settings'),
				)
				this.apply(data)
			} catch {
				this.apply({})
			}
		},

		/**
		 * Show the groups as the settings hold them.
		 *
		 * @param {object} settings The settings.
		 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
		 */
		apply(settings) {
			this.groups = provisioningGroups(settings).join(', ')
			this.loaded = true
		},

		/**
		 * Write the allowed groups.
		 *
		 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
		 */
		async save() {
			this.saving = true
			this.error = ''
			const payload = provisioningGroupsPayload(this.groups.split(','))
			try {
				const { data } = await axios.put(
					generateUrl('/apps/decidiq/api/settings'),
					payload,
				)
				this.apply(data?.config || payload)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| this.t('decidiq', 'Try again in a moment.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-member-provisioning {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-member-provisioning__title {
	font-weight: bold;
}
</style>
