<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: process-template assignment for a Governance Body.

 Assigns a default process template (`processTemplate`) and optional
 specialized templates (`additionalTemplates`) from the built-in
 catalogue (processTemplates.js). When no specialized template is
 chosen for a decision the default applies. Template MANAGEMENT
 (state machines, voting rules) is the process-configuration
 capability and is out of scope here. Saving goes through the shared
 OR object store — OpenRegister enforces per-object RBAC server-side.

 @spec openspec/specs/admin-settings/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--template" data-testid="body-template-tab">
		<h3 class="decidesk-tab__title">
			{{ t('decidesk', 'Process template') }}
		</h3>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load template assignment')">
			{{ error }}
		</CnNoteCard>

		<div v-if="loading" class="decidesk-tab__loading">
			{{ t('decidesk', 'Loading template assignment…') }}
		</div>

		<template v-else>
			<NcSelect
				v-model="defaultTemplate"
				:input-label="t('decidesk', 'Default process template')"
				:options="templateOptions"
				label="label"
				:clearable="true"
				data-testid="body-template-default" />
			<p v-if="defaultTemplate" class="decidesk-tab__hint">
				{{ defaultTemplate.description }}
			</p>

			<NcSelect
				v-model="specializedTemplates"
				:input-label="t('decidesk', 'Specialized templates')"
				:options="specializedOptions"
				label="label"
				multiple
				data-testid="body-template-specialized" />
			<p class="decidesk-tab__hint">
				{{ t('decidesk', 'Specialized templates apply to specific decision types; the default applies when none is chosen.') }}
			</p>

			<div class="decidesk-tab__footer">
				<NcButton
					type="primary"
					:disabled="saving"
					data-testid="body-template-save"
					@click="save">
					{{ saving ? t('decidesk', 'Saving…') : t('decidesk', 'Save') }}
				</NcButton>
				<span v-if="savedMessage" class="decidesk-tab__saved" data-testid="body-template-saved">
					{{ savedMessage }}
				</span>
			</div>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { ensureRelationType } from './useRelationStore.js'
import { getProcessTemplates } from './processTemplates.js'

export default {
	name: 'GovernanceBodyTemplateTab',
	components: { CnNoteCard, NcButton, NcSelect },
	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},
	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			savedMessage: '',
			body: null,
			defaultTemplate: null,
			specializedTemplates: [],
		}
	},
	computed: {
		/** @spec openspec/specs/admin-settings/spec.md */
		templateOptions() {
			return getProcessTemplates()
		},
		/** @spec openspec/specs/admin-settings/spec.md */
		specializedOptions() {
			// The default template is not offered again as a specialized one.
			return this.templateOptions.filter((tpl) => tpl.id !== this.defaultTemplate?.id)
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/admin-settings/spec.md */
			handler() { this.refresh() },
		},
	},
	methods: {
		/** @spec openspec/specs/admin-settings/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('governance-body')
				const body = await store.fetchObject('governance-body', this.objectId)
				this.body = body || null
				const templates = this.templateOptions
				this.defaultTemplate = templates.find((tpl) => tpl.id === body?.processTemplate) || null
				const assigned = Array.isArray(body?.additionalTemplates) ? body.additionalTemplates : []
				this.specializedTemplates = templates.filter((tpl) => assigned.includes(tpl.id))
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load the governance body.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/admin-settings/spec.md */
		async save() {
			this.saving = true
			this.error = ''
			this.savedMessage = ''
			try {
				const store = ensureRelationType('governance-body')
				await store.saveObject('governance-body', {
					...(this.body || {}),
					id: this.objectId,
					processTemplate: this.defaultTemplate?.id || '',
					additionalTemplates: this.specializedTemplates.map((tpl) => tpl.id),
				})
				this.savedMessage = this.t('decidesk', 'Template assignment saved')
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to save the template assignment.')
			} finally {
				this.saving = false
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
.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}
.decidesk-tab__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
.decidesk-tab__footer {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 4px;
}
.decidesk-tab__saved {
	color: var(--color-success);
}
</style>
