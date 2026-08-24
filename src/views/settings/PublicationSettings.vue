<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: per-governance-body publication configuration
 (publish-decisions-via-opencatalogi). Configures the target OpenCatalogi
 catalog, the per-type publication policy (manual-only / prompt-on-transition),
 and the attendance-rendering policy for minutes payloads.

 Rendered by the Nextcloud settings framework via AdminSettings.php — NOT added
 to the in-app vue-router (admin-router gate). Initial config + policy enums
 arrive via IInitialState/loadState.

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<div class="decidiq-pub-settings" data-testid="publication-settings">
		<h3 class="decidiq-pub-settings__title">
			{{ t('decidiq', 'Public publication') }}
		</h3>
		<p class="decidiq-pub-settings__intro">
			{{
				t(
					'decidiq',
					'Configure, per governance body, where adopted decisions, public agendas, and approved minutes are published. Anonymous read access is served exclusively through OpenCatalogi / OpenRegister — never an app-local public page.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Settings error')">
			{{ error }}
		</CnNoteCard>
		<CnNoteCard v-if="saved" type="success" :title="t('decidiq', 'Saved')">
			{{ t('decidiq', 'Publication configuration saved.') }}
		</CnNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<p v-else-if="!bodies.length" class="decidiq-pub-settings__empty">
			{{ t('decidiq', 'No governance bodies found.') }}
		</p>

		<div
			v-for="body in bodies"
			v-else
			:key="body.id"
			class="decidiq-pub-settings__body"
			:data-testid="`publication-body-${body.id}`">
			<h4>{{ body.name || body.title || body.id }}</h4>
			<div class="decidiq-pub-settings__fields">
				<label class="decidiq-pub-settings__field">
					<span>{{ t('decidiq', 'Target OpenCatalogi catalog') }}</span>
					<input
						v-model="config[body.id].catalog"
						type="text"
						:data-testid="`publication-catalog-${body.id}`"
						:placeholder="t('decidiq', 'Catalog id')" />
				</label>
				<NcSelect
					v-model="config[body.id].policy.decision"
					:inputLabel="t('decidiq', 'Decision policy')"
					:options="policyOptions"
					:reduce="(o) => o.value"
					:data-testid="`publication-policy-decision-${body.id}`" />
				<NcSelect
					v-model="config[body.id].policy.agenda"
					:inputLabel="t('decidiq', 'Agenda policy')"
					:options="policyOptions"
					:reduce="(o) => o.value"
					:data-testid="`publication-policy-agenda-${body.id}`" />
				<NcSelect
					v-model="config[body.id].policy.minutes"
					:inputLabel="t('decidiq', 'Minutes policy')"
					:options="policyOptions"
					:reduce="(o) => o.value"
					:data-testid="`publication-policy-minutes-${body.id}`" />
				<NcSelect
					v-model="config[body.id].attendance"
					:inputLabel="t('decidiq', 'Minutes attendance rendering')"
					:options="attendanceOptions"
					:reduce="(o) => o.value"
					:data-testid="`publication-attendance-${body.id}`" />
			</div>
		</div>

		<NcButton
			v-if="bodies.length"
			variant="primary"
			data-testid="publication-settings-save"
			:disabled="saving"
			@click="save">
			{{ t('decidiq', 'Save publication configuration') }}
		</NcButton>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { ensureRelationType } from '../../components/tabs/useRelationStore.js'

export default {
	name: 'PublicationSettings',
	components: { CnNoteCard, NcButton, NcLoadingIcon, NcSelect },
	data() {
		const policies = loadState('decidiq', 'publicationPolicies', {
			policies: ['manual-only', 'prompt-on-transition'],
			attendance: ['counts', 'role-holders'],
		})
		return {
			loading: false,
			saving: false,
			saved: false,
			error: '',
			bodies: [],
			config: {},
			policyEnums: policies.policies,
			attendanceEnums: policies.attendance,
			initialConfig: loadState('decidiq', 'publicationConfig', {}),
		}
	},

	computed: {
		/** @spec openspec/specs/public-publication/spec.md */
		policyOptions() {
			const labels = {
				'manual-only': this.t('decidiq', 'Manual only'),
				'prompt-on-transition': this.t('decidiq', 'Prompt on transition'),
			}
			return this.policyEnums.map((v) => ({ value: v, label: labels[v] || v }))
		},

		/** @spec openspec/specs/public-publication/spec.md */
		attendanceOptions() {
			const labels = {
				counts: this.t('decidiq', 'Counts only'),
				'role-holders': this.t('decidiq', 'Names of role-holders'),
			}
			return this.attendanceEnums.map((v) => ({
				value: v,
				label: labels[v] || v,
			}))
		},
	},

	/** @spec exclude lifecycle hook; only loads bodies + seeds config, framework setup */
	async mounted() {
		await this.loadBodies()
	},

	methods: {
		/** @spec openspec/specs/public-publication/spec.md */
		async loadBodies() {
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('governance-body')
				this.bodies =
					(await store.fetchCollection('governance-body', { _limit: 200 }))
					|| []
				const next = {}
				for (const body of this.bodies) {
					const existing = this.initialConfig[body.id] || {}
					next[body.id] = {
						catalog: existing.catalog || '',
						policy: {
							decision: existing.policy?.decision || 'manual-only',
							agenda: existing.policy?.agenda || 'manual-only',
							minutes: existing.policy?.minutes || 'manual-only',
						},

						attendance: existing.attendance || 'counts',
					}
				}
				this.config = next
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load governance bodies.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/public-publication/spec.md */
		async save() {
			this.saving = true
			this.saved = false
			this.error = ''
			try {
				const res = await fetch(
					generateUrl('/apps/decidiq/api/settings/publication-config'),
					{
						method: 'PUT',
						headers: {
							Accept: 'application/json',
							'Content-Type': 'application/json',
							requesttoken: window.OC?.requestToken,
						},
						body: JSON.stringify({ config: this.config }),
					},
				)
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(
						body.message || this.t('decidiq', 'Save failed.'),
					)
				}
				this.saved = true
			} catch (e) {
				this.error = e.message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-pub-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-pub-settings__title {
	margin: 0;
	font-weight: bold;
}

.decidiq-pub-settings__body {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidiq-pub-settings__fields {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidiq-pub-settings__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decidiq-pub-settings__field input {
	width: 100%;
}

.decidiq-pub-settings__intro,
.decidiq-pub-settings__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
