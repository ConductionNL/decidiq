<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Document details: the files of a meeting or an agenda item, each with its
 document type and first extra fields, and a Details button that opens the
 dialog to fill them in (platform-document-metadata-fields, matrix row plt-20).
 The join of files and records lives in src/utils/documentMetadata.js.

 @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
-->
<template>
	<div class="document-details" data-testid="document-details">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="document-details__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0" class="document-details__empty">
			{{ t('decidiq', 'No files attached yet.') }}
		</p>
		<ul v-else class="document-details__list">
			<li
				v-for="row in rows"
				:key="row.file.id"
				class="document-details__row"
				data-testid="document-details-row">
				<div class="document-details__text">
					<span class="document-details__name">{{
						row.file.name || row.file.title
					}}</span>
					<span class="document-details__meta">
						{{
							typeName(row.record) || t('decidiq', 'No document type')
						}}
						<template
							v-for="value in summaryOf(row.record)"
							:key="value">
							· {{ value }}
						</template>
					</span>
				</div>
				<NcButton
					variant="secondary"
					:aria-label="
						t('decidiq', 'Details of {name}', {
							name: row.file.name || row.file.title,
						})
					"
					data-testid="document-details-open"
					@click="selected = row">
					{{ t('decidiq', 'Details') }}
				</NcButton>
			</li>
		</ul>
		<DocumentMetadataModal
			v-if="selected"
			:file="selected.file"
			:record="selected.record"
			:types="offered"
			:target="target"
			:objectId="String(objectId)"
			@close="selected = null"
			@saved="onSaved" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import DocumentMetadataModal from '../../modals/DocumentMetadataModal.vue'
import {
	filesUrl,
	rowsFor,
	summary,
	TARGETS,
	typesFor,
} from '../../utils/documentMetadata.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'DocumentMetadataTab',

	components: { DocumentMetadataModal, NcButton, NcLoadingIcon },

	props: {
		objectId: { type: [String, Number], default: '' },
		/** 'meeting' or 'agenda-item'; read from the page when not given. */
		targetType: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			files: [],
			records: [],
			types: [],
			selected: null,
		}
	},

	computed: {
		/**
		 * The kind of page this list sits on.
		 *
		 * @return {string} 'meeting' or 'agenda-item'.
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
		 */
		target() {
			if (this.targetType) return this.targetType
			return String(this.$route?.name || '').startsWith('AgendaItem')
				? 'agenda-item'
				: 'meeting'
		},

		/** @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages */
		rows() {
			return rowsFor(this.files, this.records)
		},

		/** @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-001-an-administrator-declares-document-types-and-their-fields */
		offered() {
			return typesFor(this.types, this.target)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the page's files, their records and the document types.
		 *
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const field = TARGETS[this.target].field
				const [files, records, types] = await Promise.all([
					axios.get(filesUrl(this.target, this.objectId)),
					ensureRelationType('digital-document').fetchCollection(
						'digital-document',
						{ [field]: this.objectId, _limit: 200 },
					),
					ensureRelationType('document-type').fetchCollection(
						'document-type',
						{ _limit: 200 },
					),
				])
				const list = files?.data?.results ?? files?.data ?? []
				this.files = Array.isArray(list) ? list : []
				this.records = records || []
				this.types = types || []
			} catch {
				this.error = this.t(
					'decidiq',
					'The files of this page could not be read.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The name of a record's document type.
		 *
		 * @param {?object} record The details record.
		 * @return {string} The type name, or empty.
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
		 */
		typeName(record) {
			return this.typeOf(record)?.name || ''
		},

		/**
		 * The first filled-in values of a record.
		 *
		 * @param {?object} record The details record.
		 * @return {Array<string>} Up to two values.
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
		 */
		summaryOf(record) {
			return summary(record, this.typeOf(record))
		},

		/**
		 * A record's document type.
		 *
		 * @param {?object} record The details record.
		 * @return {?object} The type.
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
		 */
		typeOf(record) {
			if (!record?.type) return null
			return (
				this.types.find(
					(type) => (type.id ?? type['@self']?.id) === record.type,
				) || null
			)
		},

		/**
		 * Close the dialog and read the list again.
		 *
		 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
		 */
		async onSaved() {
			this.selected = null
			await this.load()
		},
	},
}
</script>

<style scoped>
.document-details__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.document-details__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.document-details__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.document-details__name {
	font-weight: 600;
	overflow-wrap: anywhere;
}

.document-details__meta,
.document-details__empty {
	color: var(--color-text-maxcontrast);
}

.document-details__error {
	color: var(--color-error-text);
}
</style>
