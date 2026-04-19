<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-8.1
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Versiegeschiedenis')">
		<p v-if="!versions.length" class="decidesk-empty">
			{{ t('decidesk', 'Geen versiegeschiedenis beschikbaar') }}
		</p>
		<ul v-else class="decidesk-version-list">
			<li v-for="version in versions" :key="version.version" class="decidesk-version-item">
				<div class="decidesk-version-info">
					<strong>{{ t('decidesk', 'Versie') }} {{ version.version }}</strong>
					<span class="decidesk-version-date">{{ formatDate(version.savedAt) }}</span>
					<span v-if="version.savedBy" class="decidesk-version-by">{{ t('decidesk', 'door') }} {{ version.savedBy }}</span>
				</div>
				<NcButton
					type="secondary"
					size="small"
					@click="openContentDialog(version)">
					{{ t('decidesk', 'Bekijken') }}
				</NcButton>
			</li>
		</ul>

		<div v-if="versions.length > 1" class="decidesk-comparison">
			<div class="decidesk-comparison-controls">
				<label>{{ t('decidesk', 'Vergelijken met') }}</label>
				<div class="decidesk-select-row">
					<NcSelect
						v-model="versionA"
						:options="versionOptions"
						:placeholder="t('decidesk', 'Selecteer versie A')"
						track-by="value" />
					<NcSelect
						v-model="versionB"
						:options="versionOptions"
						:placeholder="t('decidesk', 'Selecteer versie B')"
						track-by="value" />
					<NcButton
						type="secondary"
						:disabled="!versionA || !versionB || comparing"
						@click="comparVersions">
						{{ t('decidesk', 'Vergelijken') }}
					</NcButton>
				</div>
			</div>
		</div>

		<NcDialog
			v-if="showContentDialog"
			:name="t('decidesk', 'Versieinhoud')"
			:open="showContentDialog"
			@update:open="showContentDialog = false">
			<template #default>
				<pre class="decidesk-content-preview">{{ selectedContent }}</pre>
			</template>
		</NcDialog>

		<NcDialog
			v-if="showDiffDialog"
			:name="t('decidesk', 'Versievergelijking')"
			:open="showDiffDialog"
			@update:open="showDiffDialog = false">
			<template #default>
				<div class="decidesk-diff-view">
					<div
						v-for="(line, idx) in diffLines"
						:key="idx"
						:class="['decidesk-diff-line', `decidesk-diff-${line.type}`]">
						{{ line.text }}
					</div>
				</div>
			</template>
		</NcDialog>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'MinutesVersionPanel',
	components: { CnDetailCard, NcButton, NcDialog, NcSelect },
	props: {
		minutesId: { type: String, required: true },
	},
	data() {
		return {
			versions: [],
			versionA: null,
			versionB: null,
			showContentDialog: false,
			showDiffDialog: false,
			selectedContent: '',
			diffLines: [],
			comparing: false,
		}
	},
	computed: {
		versionOptions() {
			return this.versions.map(v => ({
				label: `${this.t('decidesk', 'Versie')} ${v.version} - ${this.formatDate(v.savedAt)}`,
				value: v.version,
			}))
		},
	},
	mounted() {
		this.loadVersionHistory()
	},
	watch: {
		minutesId() {
			this.loadVersionHistory()
		},
	},
	methods: {
		async loadVersionHistory() {
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.minutesId}/versions`)
				const response = await axios.get(url)
				this.versions = response.data.versions || []
			} catch (error) {
				console.error('Failed to load version history:', error)
			}
		},
		async openContentDialog(version) {
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.minutesId}/versions/${version.version}`)
				const response = await axios.get(url)
				this.selectedContent = response.data.content || ''
				this.showContentDialog = true
			} catch (error) {
				console.error('Failed to load version content:', error)
			}
		},
		async comparVersions() {
			if (!this.versionA || !this.versionB) return
			this.comparing = true
			try {
				const url = generateUrl(
					`/apps/decidesk/api/minutes/${this.minutesId}/versions/${this.versionA.value}/diff/${this.versionB.value}`
				)
				const response = await axios.get(url)
				this.diffLines = response.data.diff || []
				this.showDiffDialog = true
			} catch (error) {
				console.error('Failed to load diff:', error)
			} finally {
				this.comparing = false
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-version-list {
	list-style: none;
	margin: 0 0 var(--default-grid-baseline);
	padding: 0;
}

.decidesk-version-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-version-item:last-child {
	border-bottom: none;
}

.decidesk-version-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.decidesk-version-date {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.decidesk-version-by {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.decidesk-comparison {
	margin-top: var(--default-grid-baseline);
	padding-top: var(--default-grid-baseline);
	border-top: 1px solid var(--color-border);
}

.decidesk-comparison-controls {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.decidesk-comparison-controls label {
	font-weight: 600;
	margin-bottom: 4px;
}

.decidesk-select-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.decidesk-select-row :deep(.nc-select) {
	flex: 1;
}

.decidesk-content-preview {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-face);
	font-size: 14px;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
	margin: 0;
}

.decidesk-diff-view {
	display: flex;
	flex-direction: column;
	gap: 0;
	font-family: var(--font-face);
	font-size: 13px;
	line-height: 1.5;
	max-height: 400px;
	overflow-y: auto;
}

.decidesk-diff-line {
	padding: 4px 8px;
	white-space: pre-wrap;
	word-break: break-word;
}

.decidesk-diff-added {
	background-color: var(--color-success);
	color: var(--color-success-text);
}

.decidesk-diff-removed {
	background-color: var(--color-error);
	color: var(--color-error-text);
}

.decidesk-diff-unchanged {
	background-color: transparent;
}
</style>
