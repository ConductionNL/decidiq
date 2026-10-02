<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Add a competence the body needs, with how many members should hold it
 (bodies-board-composition-skills-and-diversity, ADR-004 modal isolation).
 Only a signatory of the body or an administrator may: the route refuses
 anyone else and the dialog shows its message.

 @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Add competence')"
		size="normal"
		data-testid="board-competence-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="board-competence__form">
				<NcTextField
					v-model="name"
					:label="t('decidiq', 'Competence')"
					data-testid="board-competence-name" />
				<NcTextField
					v-model="description"
					:label="t('decidiq', 'What the body expects (optional)')"
					data-testid="board-competence-description" />
				<NcTextField
					v-model="requiredHolders"
					type="number"
					min="1"
					:label="t('decidiq', 'Required holders')"
					data-testid="board-competence-required" />
				<p v-if="error" class="board-competence__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || !name.trim() || Number(requiredHolders) < 1"
				data-testid="board-competence-save"
				@click="save">
				{{ t('decidiq', 'Add competence') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { competencePath, competenceRequest } from '../utils/competenceApi.js'

export default {
	name: 'BoardCompetenceModal',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		/** The body that needs the competence. */
		bodyId: { type: String, required: true },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			name: '',
			description: '',
			requiredHolders: '1',
			saving: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Add the competence through the guarded route.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				await competenceRequest('POST', competencePath('competences'), {
					governanceBody: this.bodyId,
					name: this.name.trim(),
					description: this.description.trim(),
					requiredHolders: Number(this.requiredHolders),
				})
				this.$emit('saved')
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The competence could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.board-competence__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.board-competence__error {
	color: var(--color-error-text);
}
</style>
