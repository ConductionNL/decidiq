<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin process-template management section. Lists built-in (read-only) and
 custom templates, and offers create / edit / duplicate / delete. Rendered
 inside the Decidesk admin settings panel (admin-gated by the NC settings
 framework + AuthorizedAdminSetting on the controller).

 @spec openspec/specs/process-configuration/spec.md
-->
<template>
	<CnSettingsSection
		:name="t('decidesk', 'Process templates')"
		:description="t('decidesk', 'Define the state machine, voting rule and quorum policy a governance body follows. Built-in templates are read-only but can be duplicated.')">
		<div data-testid="process-templates">
			<NcButton
				variant="primary"
				data-testid="process-template-create"
				@click="openCreate">
				{{ t('decidesk', 'Create template') }}
			</NcButton>

			<NcLoadingIcon v-if="store.loading" :size="32" />

			<p v-if="store.error" class="error" data-testid="process-template-error">
				{{ store.error }}
			</p>

			<ul v-if="!store.loading" class="template-list" data-testid="process-template-list">
				<li v-for="tpl in store.templates"
					:key="tpl.id || tpl.slug"
					class="template-row"
					data-testid="process-template-item">
					<div class="template-meta">
						<strong>{{ tpl.name }}</strong>
						<span v-if="tpl.builtIn" class="builtin-badge" data-testid="process-template-builtin">{{ t('decidesk', 'Built-in') }}</span>
						<span class="context">{{ tpl.context }}</span>
					</div>
					<div class="template-actions">
						<NcButton
							v-if="!tpl.builtIn"
							:aria-label="t('decidesk', 'Edit')"
							data-testid="process-template-edit"
							@click="openEdit(tpl)">
							{{ t('decidesk', 'Edit') }}
						</NcButton>
						<NcButton
							:aria-label="t('decidesk', 'Duplicate')"
							data-testid="process-template-duplicate"
							@click="duplicate(tpl)">
							{{ t('decidesk', 'Duplicate') }}
						</NcButton>
						<NcButton
							v-if="!tpl.builtIn"
							variant="error"
							:aria-label="t('decidesk', 'Delete')"
							data-testid="process-template-delete"
							@click="remove(tpl)">
							{{ t('decidesk', 'Delete') }}
						</NcButton>
					</div>
				</li>
			</ul>

			<ProcessTemplateEditModal
				v-if="showModal"
				:template="editing"
				@close="showModal = false"
				@saved="onSaved" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import ProcessTemplateEditModal from '../../modals/ProcessTemplateEditModal.vue'
import { useProcessTemplatesStore } from '../../store/modules/processTemplates.js'

export default {
	name: 'ProcessTemplates',
	components: { NcButton, NcLoadingIcon, CnSettingsSection, ProcessTemplateEditModal },
	data() {
		return {
			store: useProcessTemplatesStore(),
			showModal: false,
			editing: null,
		}
	},
	/** @spec openspec/specs/process-configuration/spec.md */
	created() {
		this.store.fetchTemplates()
	},
	methods: {
		/** @spec openspec/specs/process-configuration/spec.md */
		openCreate() {
			this.editing = null
			this.showModal = true
		},
		/**
		 * @param tpl
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		openEdit(tpl) {
			this.editing = tpl
			this.showModal = true
		},
		/**
		 * @param tpl
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		async duplicate(tpl) {
			await this.store.duplicateTemplate(tpl.id || tpl.slug, (tpl.name || 'Template') + ' (copy)')
		},
		/**
		 * @param tpl
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		async remove(tpl) {
			await this.store.deleteTemplate(tpl.id || tpl.slug)
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		onSaved() {
			this.showModal = false
		},
	},
}
</script>

<style scoped>
.template-list {
	list-style: none;
	margin-top: 12px;
}

.template-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.template-meta {
	display: flex;
	align-items: center;
	gap: 8px;
}

.builtin-badge {
	font-size: 0.8em;
	padding: 2px 6px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.context {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.template-actions {
	display: flex;
	gap: 6px;
}

.error {
	color: var(--color-error);
}
</style>
