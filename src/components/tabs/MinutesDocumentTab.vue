<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: minutes document generation + notarial proof package
 (minutes-ui-v1).

 Lets the chair/secretary generate a minutes document (markdown, or PDF
 via Docudesk when available — the server reports honestly when it falls
 back to markdown), lists the previously generated documents recorded on
 the Minutes object, and triggers the hash-sealed notarial proof package
 for the linked meeting. All writes go through the guarded decidesk
 endpoints; the server stays authoritative.

 @spec openspec/specs/resolution-minutes/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--documents" data-testid="minutes-document-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Document generation error')">
			{{ error }}
		</CnNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="minutes">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Generate document') }}
			</h3>

			<div class="decidesk-tab__generate">
				<NcSelect
					v-model="format"
					data-testid="minutes-document-format"
					:input-label="t('decidesk', 'Document format')"
					:options="formatOptions"
					:clearable="false"
					label="label" />
				<NcButton
					variant="primary"
					data-testid="minutes-document-generate"
					:disabled="working"
					@click="generateDocument">
					{{ t('decidesk', 'Generate document') }}
				</NcButton>
			</div>

			<CnNoteCard
				v-if="lastResult && lastResult.note"
				type="warning"
				data-testid="minutes-document-note"
				:title="t('decidesk', 'Markdown fallback')">
				{{ lastResult.note }}
			</CnNoteCard>
			<p v-else-if="lastResult" class="decidesk-tab__meta" data-testid="minutes-document-result">
				{{ t('decidesk', 'Document stored at {path}', { path: lastResult.path }) }}
			</p>

			<div class="decidesk-tab__documents">
				<h3 class="decidesk-tab__title">
					{{ t('decidesk', 'Generated documents') }}
					<span class="decidesk-tab__count">({{ generatedDocuments.length }})</span>
				</h3>
				<p v-if="generatedDocuments.length === 0" class="decidesk-tab__empty">
					{{ t('decidesk', 'No documents generated yet.') }}
				</p>
				<ul v-else class="decidesk-tab__list" role="list">
					<li v-for="(doc, index) in generatedDocuments"
						:key="index"
						class="decidesk-tab__document"
						role="listitem">
						<span class="decidesk-tab__document-path">{{ doc.path }}</span>
						<span class="decidesk-tab__meta">
							{{ doc.format }} — {{ doc.generatedAt }} — {{ doc.generatedBy }}
						</span>
					</li>
				</ul>
			</div>

			<div class="decidesk-tab__proof">
				<h3 class="decidesk-tab__title">
					{{ t('decidesk', 'Notarial proof package') }}
				</h3>
				<p class="decidesk-tab__meta">
					{{ t('decidesk', 'Assembles convocation, quorum, voting results, and the adopted decision texts into a tamper-evident package in the meeting folder.') }}
				</p>
				<NcButton
					data-testid="minutes-proof-package"
					:disabled="working || !meetingId"
					@click="generateProofPackage">
					{{ t('decidesk', 'Generate proof package') }}
				</NcButton>
				<p v-if="!meetingId" class="decidesk-tab__empty">
					{{ t('decidesk', 'No meeting is linked to these minutes — the proof package needs a meeting.') }}
				</p>
				<p v-if="proofResult" class="decidesk-tab__meta" data-testid="minutes-proof-result">
					{{ t('decidesk', 'Proof package sealed (SHA-256 {hash}).', { hash: proofResult.sha256 }) }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MinutesDocumentTab',
	components: {
		CnNoteCard,
		NcButton,
		NcLoadingIcon,
		NcSelect,
	},
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			working: false,
			error: '',
			minutes: null,
			format: null,
			lastResult: null,
			proofResult: null,
		}
	},
	computed: {
		/** @spec openspec/specs/resolution-minutes/spec.md */
		formatOptions() {
			return [
				{ id: 'markdown', label: this.t('decidesk', 'Markdown') },
				{ id: 'pdf', label: this.t('decidesk', 'PDF (via Docudesk when available)') },
			]
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		generatedDocuments() {
			return Array.isArray(this.minutes?.generatedDocuments) ? this.minutes.generatedDocuments : []
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		meetingId() {
			const relation = this.minutes?.meeting
			if (!relation) return ''
			if (typeof relation === 'string') return relation
			return relation.id || ''
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/resolution-minutes/spec.md */
			handler() { this.refresh() },
		},
	},
	/** @spec exclude lifecycle wiring; seeds the default format option only */
	created() {
		this.format = this.formatOptions[0]
	},
	methods: {
		/** @spec openspec/specs/resolution-minutes/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('minutes')
				this.minutes = await store.fetchObject('minutes', this.objectId)
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load the minutes.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * POST helper against the decidesk API.
		 *
		 * @param {string} path Path under /apps/decidesk/api.
		 * @param {object} body JSON body.
		 * @return {Promise<object>} Parsed response body.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async callApi(path, body = {}) {
			const response = await fetch(generateUrl(`/apps/decidesk/api${path}`), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: window.OC?.requestToken,
				},
				body: JSON.stringify(body),
			})
			const data = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(data.message || this.t('decidesk', 'The action failed.'))
			}
			return data
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		async generateDocument() {
			this.working = true
			this.error = ''
			this.lastResult = null
			try {
				this.lastResult = await this.callApi(
					`/minutes/${this.objectId}/generate-document`,
					{ format: this.format?.id || 'markdown' },
				)
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		async generateProofPackage() {
			this.working = true
			this.error = ''
			this.proofResult = null
			try {
				this.proofResult = await this.callApi(`/meetings/${this.meetingId}/proof-package`)
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
}
.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}
.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
.decidesk-tab__generate {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: var(--default-grid-baseline);
}
.decidesk-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}
.decidesk-tab__document {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}
.decidesk-tab__document:last-child {
	border-bottom: none;
}
.decidesk-tab__document-path {
	word-break: break-all;
}
.decidesk-tab__meta,
.decidesk-tab__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
.decidesk-tab__proof {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	align-items: flex-start;
}
</style>
