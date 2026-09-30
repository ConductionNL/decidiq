<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: rights per record type (platform-role-rights-per-record-type).
 Shows per record type who may read, create, change and delete it, and lets
 the administrator add Nextcloud groups to each decidiq role. Saving
 re-imports the register, so the rules OpenRegister enforces name the
 mapped groups.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.

 @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
-->
<template>
	<div class="decidiq-role-rights" data-testid="role-rights-settings">
		<h3 class="decidiq-role-rights__title">
			{{ t('decidiq', 'Rights per record type') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					"The rules below decide who may read and change each kind of record. They name decidiq roles. Add your own groups to a role and they get the same rights; the role's own group keeps them too.",
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not save')">
			{{ error }}
		</CnNoteCard>
		<CnNoteCard v-if="saved" type="success" :title="t('decidiq', 'Saved')">
			{{ t('decidiq', 'The rules now include the groups you added.') }}
		</CnNoteCard>

		<div
			v-for="row in roles"
			:key="row.role"
			class="decidiq-role-rights__role"
			:data-testid="`role-rights-role-${row.role}`">
			<NcSelect
				v-model="row.mapped"
				:inputLabel="roleLabel(row)"
				:options="groups"
				:multiple="true"
				:taggable="false"
				:keepOpen="true" />
		</div>

		<div>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="role-rights-save"
				@click="save">
				{{ t('decidiq', 'Save groups') }}
			</NcButton>
		</div>

		<table class="decidiq-role-rights__table" data-testid="role-rights-table">
			<caption>
				{{
					t('decidiq', 'Who may do what, per record type')
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidiq', 'Record type') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Read') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Create') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Change') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Delete') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="type in recordTypes"
					:key="type.slug"
					:data-testid="`role-rights-row-${type.slug}`">
					<th scope="row">
						{{ type.title }}
						<span
							v-if="type.inherited"
							class="decidiq-role-rights__inherited">
							{{ t('decidiq', "(the app's general rules)") }}
						</span>
					</th>
					<td>{{ summary(type.rules.read) }}</td>
					<td>{{ summary(type.rules.create) }}</td>
					<td>{{ summary(type.rules.update) }}</td>
					<td>{{ summary(type.rules.delete) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { mappingOf, ruleSummary } from '../../utils/roleRights.js'

const URL = '/apps/decidiq/api/settings/role-rights'

export default {
	name: 'RoleRightsSettings',
	components: { CnNoteCard, NcButton, NcSelect },
	data() {
		return {
			roles: [],
			groups: [],
			recordTypes: [],
			saving: false,
			saved: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/** @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type */
		async load() {
			try {
				const res = await fetch(generateUrl(URL), {
					headers: { Accept: 'application/json' },
				})
				if (res.ok) {
					this.apply(await res.json())
				}
			} catch {
				this.error = t('decidiq', 'The rights could not be loaded.')
			}
		},

		/** @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type */
		apply(body) {
			this.roles = (body.roles || []).map((row) => ({
				...row,
				mapped: [...(row.mapped || [])],
			}))
			this.groups = body.groups || []
			this.recordTypes = body.recordTypes || []
		},

		/** @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type */
		async save() {
			this.saving = true
			this.saved = false
			this.error = ''
			try {
				const res = await fetch(generateUrl(URL), {
					method: 'PUT',
					headers: {
						Accept: 'application/json',
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ mapping: mappingOf(this.roles) }),
				})
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					this.error =
						body.message
						|| t('decidiq', 'The groups could not be saved.')
				} else {
					this.saved = true
				}
				if (body.roles) {
					this.apply(body)
				}
			} catch {
				this.error = t('decidiq', 'The groups could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type */
		roleLabel(row) {
			const names = {
				administrators: t('decidiq', 'Record administrators'),
				secretariat: t('decidiq', 'Secretariat'),
				'publication-flow': t('decidiq', 'Publication flow'),
			}
			return t('decidiq', 'Groups with the role {role} ({groups})', {
				role: names[row.role] || row.role,
				groups: (row.groups || []).join(', '),
			})
		},

		/** @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type */
		summary(rules) {
			return ruleSummary(rules, {
				everyone: t('decidiq', 'Everyone'),
				signedIn: t('decidiq', 'Signed-in users'),
				nobody: t('decidiq', 'Nobody'),
				conditional: t('decidiq', '{who} (under a condition)'),
			})
		},
	},
}
</script>

<style scoped>
.decidiq-role-rights__role {
	margin-block: 8px;
	max-width: 600px;
}

.decidiq-role-rights__table {
	margin-block-start: 16px;
	border-collapse: collapse;
}

.decidiq-role-rights__table th,
.decidiq-role-rights__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.decidiq-role-rights__inherited {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}
</style>
