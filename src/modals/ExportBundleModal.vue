<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Export with attachments: the selected rows or every row matching the list's
 filter, as one PDF (through filinq) or as a ZIP of the documents.

 @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Export with attachments')"
		data-testid="export-bundle-modal"
		@closing="$emit('close')">
		<template #default>
			<fieldset class="export-bundle__group">
				<legend>{{ t('decidiq', 'What to export') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="scope"
					type="radio"
					value="selected"
					name="export-bundle-scope"
					:disabled="selectedIds.length === 0"
					data-testid="export-bundle-scope-selected">
					{{
						t('decidiq', 'Selected rows ({count})', {
							count: selectedIds.length,
						})
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="scope"
					type="radio"
					value="filter"
					name="export-bundle-scope"
					data-testid="export-bundle-scope-filter">
					{{ t('decidiq', 'All rows matching the current filter') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<fieldset class="export-bundle__group">
				<legend>{{ t('decidiq', 'Format') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="format"
					type="radio"
					value="pdf"
					name="export-bundle-format"
					:disabled="!pdfAvailable"
					data-testid="export-bundle-format-pdf">
					{{
						t(
							'decidiq',
							'One PDF, each text followed by its attachments',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="format"
					type="radio"
					value="zip"
					name="export-bundle-format"
					data-testid="export-bundle-format-zip">
					{{ t('decidiq', 'A ZIP with the documents as they are') }}
				</NcCheckboxRadioSwitch>
				<p v-if="!pdfAvailable" class="export-bundle__hint">
					{{
						t(
							'decidiq',
							'One PDF needs the filinq app, which is not installed.',
						)
					}}
				</p>
			</fieldset>
			<NcNoteCard v-if="error" type="error" data-testid="export-bundle-error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard
				v-else-if="result && result.status === 'queued'"
				type="info"
				data-testid="export-bundle-queued">
				{{
					t(
						'decidiq',
						'The export is being prepared. You get a notification when {name} is ready.',
						{ name: result.name },
					)
				}}
			</NcNoteCard>
			<NcNoteCard
				v-else-if="result"
				type="success"
				data-testid="export-bundle-done">
				{{
					t('decidiq', '{name} is in your Decidiq exports folder.', {
						name: result.name,
					})
				}}
				<a :href="fileLink" target="_blank" rel="noopener">{{
					t('decidiq', 'Open the file')
				}}</a>
			</NcNoteCard>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="export-bundle-confirm"
				:disabled="busy || Boolean(result)"
				@click="submit">
				{{ t('decidiq', 'Export') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ result ? t('decidiq', 'Close') : t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
} from '@nextcloud/vue'
import { exportRequest } from '../utils/exportBundle.js'

export default {
	name: 'ExportBundleModal',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard },
	props: {
		list: { type: String, default: 'Decisions' },
		selectedIds: { type: Array, default: () => [] },
		baseFilter: { type: Object, default: () => ({}) },
	},

	emits: ['close'],
	data() {
		return {
			scope: this.selectedIds.length > 0 ? 'selected' : 'filter',
			format: 'zip',
			pdfAvailable: false,
			busy: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/**
		 * Where the finished file opens in Files.
		 *
		 * @return {string} The link.
		 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
		 */
		fileLink() {
			if (this.result && this.result.fileId) {
				return generateUrl('/f/{id}', { id: this.result.fileId })
			}
			return generateUrl('/apps/files/?dir=/Decidiq exports')
		},
	},

	/**
	 * Ask the server which formats this instance can make.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl('/apps/decidiq/api/exports/decision-bundle/formats'),
			)
			this.pdfAvailable = Boolean(data && data.pdf)
			if (this.pdfAvailable) {
				this.format = 'pdf'
			}
		} catch {
			this.pdfAvailable = false
		}
	},

	methods: {
		/**
		 * Send the export request and show the answer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				const body = exportRequest({
					format: this.format,
					scope: this.scope,
					selectedIds: this.selectedIds,
					query: this.$route ? this.$route.query : {},
					list: this.list,
					baseFilter: this.baseFilter,
				})
				const { data } = await axios.post(
					generateUrl('/apps/decidiq/api/exports/decision-bundle'),
					body,
				)
				this.result = data
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| this.t('decidiq', 'The export failed. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.export-bundle__group {
	margin-block-end: calc(var(--default-grid-baseline) * 3);
}

.export-bundle__hint {
	color: var(--color-text-maxcontrast);
}
</style>
