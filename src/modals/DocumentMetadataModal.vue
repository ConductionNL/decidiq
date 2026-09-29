<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The details of one file: its document type and the extra fields that type
 declares (platform-document-metadata-fields, ADR-004 modal isolation). The
 field inputs are AgendaItemTypeFields, which renders any type's `fields`.
 A required field is checked here and again by DocumentTypeFieldsGuardListener
 when the record is saved.

 @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Details of {name}', { name: file.name || file.title || '' })"
		size="normal"
		data-testid="document-details-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="document-details-dialog__form">
				<NcSelect
					v-model="type"
					:inputLabel="t('decidiq', 'Document type')"
					:options="types"
					label="name"
					data-testid="document-details-type" />
				<p v-if="types.length === 0" class="document-details-dialog__hint">
					{{ t('decidiq', 'No document types are offered here yet. An administrator adds them under Document types in the settings.') }}
				</p>
				<AgendaItemTypeFields
					v-model="values"
					:type="type"
					:legend="t('decidiq', 'Fields for this document type')" />
				<p v-if="error" class="document-details-dialog__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton data-testid="document-details-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="document-details-save"
				@click="save">
				{{ t('decidiq', 'Save details') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import AgendaItemTypeFields from '../components/AgendaItemTypeFields.vue'
import { ensureRelationType } from '../components/tabs/useRelationStore.js'
import { missingRequiredTypeFields, typeFieldInputs } from '../utils/agendaItemTypeFields.js'
import { detailsPayload } from '../utils/documentMetadata.js'

export default {
	name: 'DocumentMetadataModal',

	components: { AgendaItemTypeFields, NcButton, NcDialog, NcSelect },

	props: {
		/** The file (id, name, mimetype). */
		file: { type: Object, required: true },
		/** Its details record, when it has one. */
		record: { type: Object, default: null },
		/** The document types offered on this page. */
		types: { type: Array, default: () => [] },
		/** 'meeting' or 'agenda-item'. */
		target: { type: String, required: true },
		/** The meeting or agenda item. */
		objectId: { type: String, required: true },
	},

	emits: ['close', 'saved'],

	data() {
		const typeId = this.record?.type
		return {
			type: this.types.find((type) => (type.id ?? type['@self']?.id) === typeId) || null,
			values: { ...(this.record?.typeFields || {}) },
			saving: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Save the file's details, refusing an empty required field.
		 *
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
		 */
		async save() {
			this.error = ''
			const missing = missingRequiredTypeFields(typeFieldInputs(this.type), this.values)
			if (missing.length > 0) {
				this.error = this.t('decidiq', 'Fill in {fields}.', { fields: missing.join(', ') })
				return
			}
			this.saving = true
			try {
				await ensureRelationType('digital-document').saveObject(
					'digital-document',
					detailsPayload({
						file: this.file,
						target: this.target,
						objectId: this.objectId,
						type: this.type,
						typeFields: this.values,
						existing: this.record,
					}),
				)
				this.$emit('saved')
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The details could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.document-details-dialog__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.document-details-dialog__hint {
	color: var(--color-text-maxcontrast);
}

.document-details-dialog__error {
	color: var(--color-error-text);
}
</style>
