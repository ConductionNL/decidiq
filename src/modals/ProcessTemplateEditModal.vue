<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Create / edit a process template: name + context, the state-machine editor,
 and the default voting rule. The transition graph is validated client-side for
 fast feedback; the server (ProcessTemplateService::validateStateMachine) is the
 authority on save.

 @spec openspec/specs/process-configuration/spec.md
-->
<template>
	<NcModal
		size="large"
		data-testid="process-template-modal"
		@close="$emit('close')">
		<div class="process-template-modal">
			<h2>{{ isEdit ? t('decidesk', 'Edit process template') : t('decidesk', 'Create process template') }}</h2>

			<div class="form-group">
				<label for="pt-name">{{ t('decidesk', 'Name') }}</label>
				<input
					id="pt-name"
					v-model="form.name"
					type="text"
					data-testid="process-template-name"
					:placeholder="t('decidesk', 'e.g. ALV Statute Amendment')">
			</div>

			<div class="form-group">
				<label for="pt-description">{{ t('decidesk', 'Description') }}</label>
				<input
					id="pt-description"
					v-model="form.description"
					type="text"
					data-testid="process-template-description">
			</div>

			<div class="form-group">
				<NcSelect
					v-model="form.context"
					:input-label="t('decidesk', 'Governance context')"
					:options="contextOptions"
					data-testid="process-template-context" />
			</div>

			<StateMachineEditor v-model="form" data-testid="process-template-state-machine" />

			<h3>{{ t('decidesk', 'Default voting rule') }}</h3>
			<div class="form-group">
				<NcSelect
					v-model="form.votingRule.voteThreshold"
					:input-label="t('decidesk', 'Majority threshold')"
					:options="thresholdOptions"
					data-testid="process-template-threshold" />
			</div>
			<div class="form-group">
				<NcSelect
					v-model="form.votingRule.abstentionHandling"
					:input-label="t('decidesk', 'Abstention handling')"
					:options="abstentionOptions"
					data-testid="process-template-abstention" />
			</div>
			<div class="form-group">
				<NcSelect
					v-model="form.votingRule.tieBreakRule"
					:input-label="t('decidesk', 'Tie-break rule')"
					:options="tieBreakOptions"
					data-testid="process-template-tiebreak" />
			</div>

			<div class="form-group form-group--checkbox">
				<input
					id="pt-quorum"
					v-model="form.quorumRequired"
					type="checkbox"
					:aria-label="t('decidesk', 'Quorum required')">
				<label for="pt-quorum">{{ t('decidesk', 'Quorum required before voting') }}</label>
			</div>

			<div v-if="validation.errors.length" class="validation-errors" data-testid="process-template-errors">
				<p v-for="(err, i) in validation.errors" :key="i">{{ err }}</p>
			</div>

			<div class="modal-actions">
				<NcButton
					variant="primary"
					data-testid="process-template-save"
					:disabled="saving || !validation.valid || !form.name"
					@click="save">
					{{ saving ? t('decidesk', 'Saving...') : t('decidesk', 'Save') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('decidesk', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcSelect } from '@nextcloud/vue'
import StateMachineEditor from '../components/processTemplates/StateMachineEditor.vue'
import { useProcessTemplatesStore } from '../store/modules/processTemplates.js'
import { validateStateMachineGraph } from '../services/processTemplateGraph.js'

export default {
	name: 'ProcessTemplateEditModal',
	components: { NcButton, NcModal, NcSelect, StateMachineEditor },
	props: {
		template: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			saving: false,
			form: this.buildForm(this.template),
		}
	},
	computed: {
		/** @spec openspec/specs/process-configuration/spec.md */
		isEdit() {
			return !!(this.template && this.template.id)
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		validation() {
			return validateStateMachineGraph(this.form)
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		contextOptions() {
			return ['association', 'corporate', 'legislative', 'operations', 'citizen']
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		thresholdOptions() {
			return ['simple-majority', 'qualified-majority-two-thirds', 'qualified-majority-three-quarters', 'unanimous']
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		abstentionOptions() {
			return ['exclude', 'count']
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		tieBreakOptions() {
			return ['rejected', 'chair-decides', 'revote']
		},
	},
	methods: {
		/** @spec openspec/specs/process-configuration/spec.md */
		buildForm(template) {
			const t = template || {}
			return {
				name: t.name || '',
				description: t.description || '',
				context: t.context || 'association',
				initialState: t.initialState || 'draft',
				stateMachine: {
					states: (t.stateMachine?.states || [{ name: 'draft' }, { name: 'proposed' }, { name: 'decided' }]).map((s) => ({ ...s })),
					transitions: (t.stateMachine?.transitions || [
						{ from: 'draft', to: 'proposed' },
						{ from: 'proposed', to: 'decided' },
					]).map((tr) => ({ ...tr })),
				},
				votingRule: {
					voteThreshold: t.votingRule?.voteThreshold || 'simple-majority',
					abstentionHandling: t.votingRule?.abstentionHandling || 'exclude',
					tieBreakRule: t.votingRule?.tieBreakRule || 'rejected',
				},
				quorumRequired: t.quorumRequired !== false,
				allowDecideWithoutVote: t.allowDecideWithoutVote === true,
			}
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		async save() {
			if (!this.validation.valid) {
				return
			}
			this.saving = true
			const store = useProcessTemplatesStore()
			const payload = { ...this.form }
			const result = this.isEdit
				? await store.updateTemplate(this.template.id, payload)
				: await store.createTemplate(payload)
			this.saving = false
			if (result) {
				this.$emit('saved', result)
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.process-template-modal {
	padding: 16px;
	max-height: 80vh;
	overflow-y: auto;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.form-group--checkbox {
	display: flex;
	align-items: center;
	gap: 8px;
}

.form-group--checkbox label {
	margin-bottom: 0;
}

.validation-errors {
	color: var(--color-error);
	margin: 8px 0;
}

.modal-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
