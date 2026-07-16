<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Add-relation dialog for the Related decisions tab (decision-detail-fullpicture
 C6, REQ-RTU-002). Selects an EXISTING target decision via NcSelect object
 search (never creates a child) plus a relation-type selector, and confirms.
 Server validation errors (self-reference, cycle, authority) are surfaced
 inline — the dialog stays open until the parent calls setError() / setSuccess().
 Lives in src/modals/ per the modal-isolation rule.

 @spec openspec/specs/relation-tab-ui/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Add related decision')"
		data-testid="related-decision-add-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="decidesk-relation-add">
				<NcSelect
					v-model="selectedType"
					data-testid="related-decision-type"
					:input-label="t('decidesk', 'Relation type')"
					:options="typeOptions"
					:reduce="(o) => o.value"
					label="label"
					:clearable="false" />

				<NcSelect
					v-model="selectedTarget"
					data-testid="related-decision-target"
					:input-label="t('decidesk', 'Target decision')"
					:options="targetOptions"
					:loading="searching"
					:filterable="false"
					label="title"
					:placeholder="t('decidesk', 'Search decisions…')"
					@search="onSearch" />

				<CnNoteCard
					v-if="error"
					type="error"
					data-testid="related-decision-add-error"
					:title="t('decidesk', 'Relation rejected')">
					{{ error }}
				</CnNoteCard>
			</div>
		</template>
		<template #actions>
			<NcButton
				type="primary"
				data-testid="related-decision-add-confirm"
				:disabled="busy || !selectedTarget || !selectedType"
				@click="confirm">
				{{ t('decidesk', 'Add relation') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'RelatedDecisionAddModal',
	components: { CnNoteCard, NcButton, NcDialog, NcSelect },
	props: {
		/** Relation type options: [{ value, label }]. */
		typeOptions: { type: Array, default: () => [] },
		/** Async search callback: (query) => Promise<object[]>. */
		searchFn: { type: Function, required: true },
	},
	data() {
		return {
			selectedType: this.typeOptions[0]?.value || '',
			selectedTarget: null,
			targetOptions: [],
			searching: false,
			busy: false,
			error: '',
		}
	},
	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async onSearch(query) {
			this.searching = true
			try {
				this.targetOptions = (await this.searchFn(query)) || []
			} catch (e) {
				this.targetOptions = []
			} finally {
				this.searching = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		confirm() {
			this.error = ''
			this.busy = true
			this.$emit('confirm', {
				type: this.selectedType,
				target: this.selectedTarget,
			})
		},
		/**
		 * Called by the parent when the server rejects the relation.
		 * Keeps the dialog open and shows the message inline.
		 *
		 * @param {string} message The server error message.
		 * @return {void}
		 */
		setError(message) {
			this.error = message
			this.busy = false
		},
	},
}
</script>

<style scoped>
.decidesk-relation-add {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-bottom: var(--default-grid-baseline);
}
</style>
