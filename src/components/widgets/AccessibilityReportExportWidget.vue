<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AccessibilityReportExportWidget — the `accessibility-report-export` dashboard
 widget of the document accessibility report (document-accessibility-check
 REQ-005). The tiles beside it show the figures declaratively; this widget
 states that they come from a heuristic check and downloads them as a CSV
 for the body and period the page is set to, with that statement in the file.
 The shared export has no room for the statement, which is the one reason
 this widget exists. Registered by registerReportWidgets.js.

 @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
-->
<template>
	<div class="accessibility-report-export" data-testid="accessibility-report-export">
		<p class="accessibility-report-export__disclaimer">
			{{ disclaimer }}
		</p>
		<NcButton
			variant="secondary"
			:disabled="working"
			data-testid="accessibility-report-export-button"
			@click="exportCsv">
			<template #icon>
				<NcLoadingIcon v-if="working" :size="20" />
			</template>
			{{ t('decidiq', 'Export report (CSV)') }}
		</NcButton>
		<p v-if="error" class="accessibility-report-export__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { readFigures, reportCsv, reportRows } from '../../utils/accessibilityReport.js'

export default {
	name: 'AccessibilityReportExportWidget',

	components: { NcButton, NcLoadingIcon },

	inject: {
		/** The dashboard's reactive workspace: `governanceBody`, `dateFrom`, `dateTo`. */
		cnWorkspaceContext: { default: null },
	},

	props: {
		/** `register`, `schema`, `filter`, `overrides: {schema, filter}`, `bodySchema`, `fileName`. */
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return { working: false, error: '' }
	},

	computed: {
		/** @return {object} The page's workspace values. */
		workspace() {
			const context = this.cnWorkspaceContext
			const value = context && typeof context === 'object' && 'value' in context ? context.value : context
			return value && typeof value === 'object' ? value : {}
		},

		/** @return {string} */
		disclaimer() {
			return this.t('decidiq', 'These figures come from a heuristic check of the documents, not from a certified PDF/UA or EN 301 549 audit.')
		},
	},

	methods: {
		/**
		 * The status labels of the report rows.
		 *
		 * @param {string} status The status.
		 * @return {string}
		 */
		statusLabel(status) {
			return {
				pass: this.t('decidiq', 'Passed'),
				warnings: this.t('decidiq', 'With warnings'),
				fail: this.t('decidiq', 'Failed'),
				'not-scanned': this.t('decidiq', 'Not checked'),
			}[status] || status
		},

		/**
		 * The name of the page's body, or "All bodies".
		 *
		 * @return {Promise<string>}
		 */
		async bodyName() {
			const id = this.workspace.governanceBody
			if (!id) {
				return this.t('decidiq', 'All bodies')
			}
			try {
				const { data } = await axios.get(this.bodyUrl(id))
				return String(data?.name || data?.title || id)
			} catch {
				return String(id)
			}
		},

		/**
		 * The OpenRegister address of a governance body.
		 *
		 * @param {string} id The body.
		 * @return {string} The URL.
		 */
		bodyUrl(id) {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', {
				register: this.content.register || 'decidiq',
				schema: this.content.bodySchema || 'governance-body',
				id,
			})
		},

		/** @return {string} The period the page is set to. */
		periodLabel() {
			const from = String(this.workspace.dateFrom || '').slice(0, 10)
			const to = String(this.workspace.dateTo || '').slice(0, 10)
			if (from === '' && to === '') {
				return this.t('decidiq', 'All time')
			}
			return `${from || '…'} – ${to || '…'}`
		},

		/**
		 * Read the figures again and download them as a CSV.
		 *
		 * @return {Promise<void>}
		 */
		async exportCsv() {
			this.working = true
			this.error = ''
			try {
				const figures = await readFigures(this.content, this.workspace)
				const csv = reportCsv({
					title: this.t('decidiq', 'Document accessibility'),
					scope: [
						[this.t('decidiq', 'Governance body'), await this.bodyName()],
						[this.t('decidiq', 'Period'), this.periodLabel()],
					],
					headers: {
						result: this.t('decidiq', 'Result of the heuristic check'),
						documents: this.t('decidiq', 'Documents'),
						share: this.t('decidiq', 'Share'),
					},
					rows: reportRows(figures.counts).map((row) => ({ ...row, label: this.statusLabel(row.status) })),
					overrides: { label: this.t('decidiq', 'Published past the check'), count: figures.overrides },
					disclaimer: this.disclaimer,
				})
				this.download(csv)
			} catch {
				this.error = this.t('decidiq', 'The report could not be exported.')
			} finally {
				this.working = false
			}
		},

		/**
		 * Hand the CSV to the browser as a file.
		 *
		 * @param {string} csv The CSV.
		 * @return {void}
		 */
		download(csv) {
			const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = this.content.fileName || 'accessibility-report.csv'
			document.body.appendChild(link)
			link.click()
			link.remove()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.accessibility-report-export {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3);
}

.accessibility-report-export__disclaimer {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.accessibility-report-export__error {
	color: var(--color-error-text, var(--color-error));
	margin: 0;
}
</style>
