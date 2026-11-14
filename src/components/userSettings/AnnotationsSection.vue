<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings: my notes (document-annotations task 7, REQ-ANN-006). The
 annotations a member makes on meeting papers are personal notes, so the
 member downloads all of their own as JSON or CSV, or deletes all of them,
 shared ones included. Only the member's own annotations are ever in either.

 @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
-->
<template>
	<div class="user-settings-section" data-testid="annotations-section">
		<p class="user-settings-section__hint">
			{{
				t(
					'decidiq',
					'Your highlights, notes and drawings on meeting papers. Only you can export or delete them.',
				)
			}}
		</p>
		<div class="annotations-section__actions">
			<NcButton
				:href="exportJson"
				download="annotations.json"
				data-testid="annotations-export-json">
				{{ t('decidiq', 'Export my notes (JSON)') }}
			</NcButton>
			<NcButton
				:href="exportCsv"
				download="annotations.csv"
				data-testid="annotations-export-csv">
				{{ t('decidiq', 'Export my notes (CSV)') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="annotations-delete-all"
				@click="confirming = true">
				{{ t('decidiq', 'Delete all my notes') }}
			</NcButton>
		</div>
		<p v-if="notice" class="user-settings-section__hint" role="status">
			{{ notice }}
		</p>
		<AnnotationDeleteDialog
			v-if="confirming"
			:title="t('decidiq', 'Delete all my notes')"
			:message="
				t(
					'decidiq',
					'Delete all your annotations on meeting papers, including the ones you shared? This cannot be undone.',
				)
			"
			:busy="deleting"
			:error="error"
			@confirm="deleteAll"
			@close="confirming = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import AnnotationDeleteDialog from '../../modals/AnnotationDeleteDialog.vue'
import { useAnnotationStore } from '../../store/annotation.js'
import { exportUrl } from '../../utils/annotations.js'

export default {
	name: 'AnnotationsSection',

	components: { AnnotationDeleteDialog, NcButton },

	data() {
		return {
			confirming: false,
			deleting: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/** @return {string} The JSON download. */
		exportJson() {
			return exportUrl('json')
		},

		/** @return {string} The CSV download. */
		exportCsv() {
			return exportUrl('csv')
		},
	},

	methods: {
		/**
		 * Delete every own annotation.
		 *
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
		 */
		async deleteAll() {
			this.deleting = true
			this.error = ''
			try {
				const deleted = await useAnnotationStore().deleteOwn()
				this.notice = this.n(
					'decidiq',
					'{count} note deleted.',
					'{count} notes deleted.',
					deleted,
					{ count: deleted },
				)
				this.confirming = false
			} catch (error) {
				this.error = error.message
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>

<style scoped src="./userSettingsFields.css"></style>

<style scoped>
.annotations-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}
</style>
