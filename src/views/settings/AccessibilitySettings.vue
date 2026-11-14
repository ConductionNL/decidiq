<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: accessibility (board DcBeheerSysteem,
 Toegankelijkheid). Shows the last WCAG 2.1 AA audit the app ships
 (lib/Settings/accessibility-audit.json, written by
 scripts/wcag-audit-report.mjs): the standard, the sample, the result and
 every finding with its page and owner, and offers the findings as a file.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.
-->
<template>
	<div class="decidiq-accessibility" data-testid="accessibility-settings">
		<h3 class="decidiq-accessibility__title">
			{{ t('decidiq', 'Accessibility') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'The audit report shows whether the public pages meet WCAG 2.1 AA.',
				)
			}}
		</p>

		<template v-if="view.available">
			<dl class="decidiq-accessibility__facts">
				<dt>{{ t('decidiq', 'Standard') }}</dt>
				<dd>{{ view.standard }}</dd>
				<dt>{{ t('decidiq', 'Sample') }}</dt>
				<dd>
					{{
						t('decidiq', '{count} pages, {date}', {
							count: view.sampleSize,
							date: view.date,
						})
					}}
				</dd>
				<dt>{{ t('decidiq', 'Result') }}</dt>
				<dd>
					{{
						t('decidiq', '{count} findings', {
							count: view.findingCount,
						})
					}}
				</dd>
			</dl>

			<table v-if="view.findingCount > 0" class="decidiq-accessibility__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Finding') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Page') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Owner') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(finding, index) in view.findings"
						:key="`${finding.criterion}-${index}`">
						<td>{{ finding.criterion }} {{ finding.name }}</td>
						<td>{{ finding.page }}</td>
						<td>{{ finding.owner }}</td>
					</tr>
				</tbody>
			</table>

			<p>
				<a
					:href="downloadHref"
					download="decidiq-accessibility-audit.json"
					class="decidiq-accessibility__download">
					{{ t('decidiq', 'Download report') }}
				</a>
			</p>
		</template>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { auditView } from '../../utils/accessibilityAudit.js'

export default {
	name: 'AccessibilitySettings',
	data() {
		return {
			digest: loadState('decidiq', 'accessibilityAudit', null),
		}
	},

	computed: {
		/** @return {object} What the section shows. */
		view() {
			return auditView(this.digest)
		},

		/** @return {string} The digest as a file to save. */
		downloadHref() {
			return `data:application/json;charset=utf-8,${encodeURIComponent(JSON.stringify(this.digest, null, 2))}`
		},
	},
}
</script>

<style scoped>
.decidiq-accessibility {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-accessibility__title {
	font-weight: bold;
}

.decidiq-accessibility__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: calc(var(--default-grid-baseline)) calc(var(--default-grid-baseline) * 4);
}

.decidiq-accessibility__facts dt {
	color: var(--color-text-maxcontrast);
}

.decidiq-accessibility__table {
	border-collapse: collapse;
	width: 100%;
}

.decidiq-accessibility__table th,
.decidiq-accessibility__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-block-end: 1px solid var(--color-border);
	text-align: start;
}

.decidiq-accessibility__download {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
