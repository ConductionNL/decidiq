<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Accessibility: each file of a meeting or an agenda item with its
 accessibility badge. Opening a badge shows the findings with what was seen
 and how to fix it in the source document; a file not checked yet can be
 checked now (POST /api/accessibility/scan).

 @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-004-remediation-guidance-per-finding
-->
<template>
	<div class="decidiq-document-accessibility" data-testid="document-accessibility">
		<p class="decidiq-document-accessibility__note">
			{{
				t(
					'decidiq',
					'The check is a heuristic: it finds common problems, it does not certify a document as accessible.',
				)
			}}
		</p>
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="decidiq-document-accessibility__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0">
			{{ t('decidiq', 'No files attached yet.') }}
		</p>
		<ul v-else class="decidiq-document-accessibility__list">
			<li
				v-for="row in rows"
				:key="row.fileId"
				class="decidiq-document-accessibility__row"
				data-testid="document-accessibility-row">
				<div class="decidiq-document-accessibility__head">
					<span class="decidiq-document-accessibility__name">{{ row.name }}</span>
					<AccessibilityBadge
						:status="row.status"
						:expanded="open === row.fileId"
						@toggle="toggle(row.fileId)" />
					<NcButton
						v-if="row.status === 'not-scanned'"
						variant="secondary"
						:disabled="scanning === row.fileId"
						:aria-label="t('decidiq', 'Check {name} now', { name: row.name })"
						data-testid="document-accessibility-scan"
						@click="scan(row)">
						{{ t('decidiq', 'Check now') }}
					</NcButton>
				</div>
				<div
					v-if="open === row.fileId"
					class="decidiq-document-accessibility__detail"
					data-testid="document-accessibility-detail">
					<p v-if="row.findings.length === 0">
						{{ t('decidiq', 'The check found no problems.') }}
					</p>
					<ul v-else>
						<li v-for="finding in row.findings" :key="finding.code">
							<strong>{{ finding.guidance || finding.code }}</strong>
							<span v-if="finding.evidence" class="decidiq-document-accessibility__evidence">
								{{ t('decidiq', 'Seen: {evidence}', { evidence: finding.evidence }) }}
							</span>
						</li>
					</ul>
				</div>
			</li>
		</ul>
		<p v-if="notice" role="status">
			{{ notice }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AccessibilityBadge from '../AccessibilityBadge.vue'
import {
	currentReports,
	findingRows,
	reportsUrl,
	scanUrl,
} from '../../utils/documentAccessibility.js'
import { filesUrl } from '../../utils/documentMetadata.js'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'DocumentAccessibilityTab',

	components: { AccessibilityBadge, NcButton, NcLoadingIcon },

	mixins: [objectIdLoader],

	props: {
		targetType: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			notice: '',
			files: [],
			reports: [],
			open: null,
			scanning: null,
		}
	},

	computed: {
		/** The kind of page this list sits on: 'meeting' or 'agenda-item'. */
		target() {
			if (this.targetType) return this.targetType
			return String(this.$route?.name || '').startsWith('AgendaItem')
				? 'agenda-item'
				: 'meeting'
		},

		/** Each file with its current report's status and findings. */
		rows() {
			const current = currentReports(this.reports)
			return this.files.map((file) => {
				const fileId = Number(file.id ?? file.fileId)
				const report = current.get(fileId) || null
				return {
					fileId,
					name: String(file.name ?? file.title ?? file.path ?? fileId),
					status: report?.status || 'not-scanned',
					findings: findingRows(report),
				}
			})
		},
	},

	methods: {
		/** Read the page's files and their reports. */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const [files, reports] = await Promise.all([
					axios.get(filesUrl(this.target, this.objectId)),
					axios.get(reportsUrl(this.objectId)).catch(() => ({ data: [] })),
				])
				const list = files?.data?.results ?? files?.data ?? []
				this.files = Array.isArray(list) ? list : []
				const found = reports?.data?.results ?? reports?.data ?? []
				this.reports = Array.isArray(found) ? found : []
			} catch {
				this.error = this.t('decidiq', 'The files of this page could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open or close a file's scan detail.
		 *
		 * @param {number} fileId The file.
		 */
		toggle(fileId) {
			this.open = this.open === fileId ? null : fileId
		},

		/**
		 * Check one file now and show its report.
		 *
		 * @param {object} row The file's row.
		 */
		async scan(row) {
			this.scanning = row.fileId
			this.notice = ''
			try {
				const { data } = await axios.post(scanUrl(), {
					sourceObject: this.objectId,
					fileId: row.fileId,
				}, { validateStatus: (status) => status === 200 || status === 413 })
				this.reports = [...this.reports, data]
				this.open = row.fileId
			} catch {
				this.notice = this.t('decidiq', 'The file could not be checked.')
			} finally {
				this.scanning = null
			}
		},
	},
}
</script>

<style scoped>
.decidiq-document-accessibility__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decidiq-document-accessibility__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decidiq-document-accessibility__name {
	font-weight: bold;
}

.decidiq-document-accessibility__detail {
	margin-block-start: var(--default-grid-baseline);
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
}

.decidiq-document-accessibility__evidence,
.decidiq-document-accessibility__note {
	display: block;
	color: var(--color-text-maxcontrast);
}

.decidiq-document-accessibility__error {
	color: var(--color-error-text);
}
</style>
