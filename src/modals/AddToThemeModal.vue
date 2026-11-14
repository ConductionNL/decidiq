<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Add-to-theme dialog (publication-theme-pages, REQ-THP-001): the griffier
 picks an existing theme and the agenda item or decision this dialog was
 opened from is added to it. The parent stores the link and calls setError()
 when the server refuses it; the dialog stays open until then. Lives in
 src/modals/ per the modal-isolation rule.

 @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Add to theme')"
		data-testid="add-to-theme-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-add-to-theme">
				<NcSelect
					v-model="selected"
					data-testid="add-to-theme-select"
					:inputLabel="t('decidiq', 'Theme')"
					:options="options"
					label="title"
					:placeholder="t('decidiq', 'Choose a theme…')" />
				<p v-if="options.length === 0" class="decidiq-add-to-theme__empty" data-testid="add-to-theme-none">
					{{ t('decidiq', 'There is no theme to add this to yet. Create one under Theme pages.') }}
				</p>
				<CnNoteCard
					v-if="error"
					type="error"
					data-testid="add-to-theme-error"
					:title="t('decidiq', 'Could not add it to the theme')">
					{{ error }}
				</CnNoteCard>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="add-to-theme-confirm"
				:disabled="busy || !selected"
				@click="confirm">
				{{ t('decidiq', 'Add to theme') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'AddToThemeModal',
	components: { CnNoteCard, NcButton, NcDialog, NcSelect },
	props: {
		/** The themes the object is not in yet: [{ id, title, … }]. */
		themes: { type: Array, default: () => [] },
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			selected: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		options() {
			return this.themes
		},
	},

	methods: {
		/** @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme */
		confirm() {
			this.error = ''
			this.busy = true
			this.$emit('confirm', this.selected)
		},

		/**
		 * Called by the parent when the server refuses the link.
		 *
		 * @param {string} message The server's message.
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
.decidiq-add-to-theme {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-bottom: var(--default-grid-baseline);
}

.decidiq-add-to-theme__empty {
	color: var(--color-text-maxcontrast);
}
</style>
