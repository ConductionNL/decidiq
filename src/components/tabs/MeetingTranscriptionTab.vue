<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting transcription panel (meeting-transcription-ai-minutes).

 On the meeting detail view: pick a transcription source (uploaded meeting-folder
 file or discovered Talk recording), confirm consent, request transcription,
 watch the status lifecycle, browse the transcript grouped per agenda item, and
 — when an AI provider is available — generate draft minutes with a provenance
 banner and per-section accept/edit/discard markers plus unverified-suggestion
 flags. Provider absence is shown as an explanation, never an error.

 @spec openspec/specs/meeting-transcription/spec.md
-->
<template>
	<div
		class="decidesk-tab decidesk-tab--transcription"
		data-testid="meeting-transcription-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Transcription') }}
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Transcription error')">
			{{ error }}
		</CnNoteCard>

		<!-- Provider-unavailable messaging (first-class state, not an error). -->
		<CnNoteCard
			v-if="loaded && !providerAvailable"
			type="warning"
			data-testid="transcription-unavailable"
			:title="t('decidesk', 'Transcription unavailable')">
			{{
				t(
					'decidesk',
					'No speech-to-text provider is installed on this instance. You can still attach a recording and record consent; transcription becomes available once a provider (e.g. a local Whisper app) is installed.',
				)
			}}
		</CnNoteCard>

		<!-- Attach a source. -->
		<section class="decidesk-transcription__attach">
			<label
				class="decidesk-transcription__label"
				for="transcription-source-select">
				{{ t('decidesk', 'Recording source') }}
			</label>
			<!--
				@nextcloud/vue v9 renamed the NcSelect model to
				`modelValue` / `update:modelValue` and DELETED the v8 pair:
				there is no `value` prop left to bind and the deprecated
				`input` event is no longer emitted. The v8 form still renders a
				working-looking combobox that opens, filters and highlights an
				option — it simply never tells the parent what was picked, so
				`selectedSource` stayed null and "Attach recording" stayed
				disabled forever.
			-->
			<NcSelect
				input-id="transcription-source-select"
				data-testid="transcription-source-select"
				:input-label="t('decidesk', 'Recording source')"
				:options="sourceOptions"
				:model-value="selectedSource"
				label="label"
				@update:model-value="onSelectSource" />
			<p
				v-if="loaded && sourceOptions.length === 0"
				class="decidesk-transcription__hint">
				{{
					t(
						'decidesk',
						"No audio files found in this meeting's folder. Upload a recording to the meeting folder, then refresh.",
					)
				}}
			</p>
			<NcButton
				variant="primary"
				data-testid="transcription-attach"
				:disabled="!selectedSource"
				@click="openConsent">
				{{ t('decidesk', 'Attach recording') }}
			</NcButton>
		</section>

		<!-- Existing transcript + lifecycle. -->
		<section
			v-if="transcript"
			class="decidesk-transcription__status"
			data-testid="transcription-status">
			<CnStatusBadge :label="statusLabel" :color-map="statusColors" />
			<p
				v-if="transcript.status === 'failed' && transcript.failureReason"
				class="decidesk-transcription__hint">
				{{ t('decidesk', 'Reason:') }} {{ transcript.failureReason }}
			</p>
			<NcButton
				v-if="canTranscribe"
				variant="secondary"
				data-testid="transcription-transcribe"
				:disabled="working"
				@click="transcribe">
				{{
					transcript.status === 'failed'
						? t('decidesk', 'Retry transcription')
						: t('decidesk', 'Transcribe')
				}}
			</NcButton>
			<NcButton
				v-if="transcript.status === 'done'"
				variant="tertiary"
				data-testid="transcription-realign"
				:disabled="working"
				@click="realign">
				{{ t('decidesk', 'Re-align to agenda') }}
			</NcButton>
		</section>

		<!-- Transcript grouped per agenda item. -->
		<section
			v-if="transcript && transcript.status === 'done'"
			class="decidesk-transcription__transcript"
			data-testid="transcript-view">
			<div
				v-for="group in groupedSegments"
				:key="group.key"
				class="decidesk-transcription__group"
				:data-testid="
					group.key === 'unassigned'
						? 'transcript-group-unassigned'
						: 'transcript-group'
				">
				<h4 class="decidesk-transcription__group-title">
					{{ group.title }}
				</h4>
				<p
					v-for="(seg, i) in group.segments"
					:key="i"
					class="decidesk-transcription__segment">
					<strong>{{ seg.speakerLabel }}:</strong> {{ seg.text }}
				</p>
			</div>
		</section>

		<!-- Generate draft (hidden without an AI provider). -->
		<section
			v-if="transcript && transcript.status === 'done' && aiAvailable"
			class="decidesk-transcription__draft">
			<NcButton
				variant="primary"
				data-testid="transcription-generate-draft"
				:disabled="working"
				@click="generateDraft">
				{{ t('decidesk', 'Generate draft minutes') }}
			</NcButton>
		</section>

		<!-- Draft review banner + per-section markers. -->
		<section
			v-if="draft"
			class="decidesk-transcription__draft-review"
			data-testid="draft-review">
			<CnNoteCard
				type="info"
				data-testid="draft-provenance-banner"
				:title="t('decidesk', 'AI-generated draft')">
				{{
					t(
						'decidesk',
						'This draft was generated by AI from the transcript. Review every section before it enters the minutes. AI never approves or publishes minutes — the normal approval workflow is unchanged.',
					)
				}}
			</CnNoteCard>

			<div
				v-for="(section, idx) in draft.sections"
				:key="idx"
				class="decidesk-transcription__section"
				:data-testid="
					section.discarded ? 'draft-section-discarded' : 'draft-section'
				">
				<div class="decidesk-transcription__section-head">
					<h4>{{ section.title }}</h4>
					<span
						v-if="!section.discarded"
						class="decidesk-transcription__ai-marker"
						data-testid="ai-section-marker">
						{{ t('decidesk', 'AI') }}
					</span>
				</div>

				<NcTextArea
					v-if="!section.discarded"
					v-model="section.summary"
					:label="t('decidesk', 'Section summary')"
					resize="vertical"
					@update:model-value="markEdited(section)" />
				<p v-else class="decidesk-transcription__hint">
					{{
						t(
							'decidesk',
							'Section discarded — write your own text in the minutes editor.',
						)
					}}
				</p>

				<ul
					v-if="
						!section.discarded
						&& section.suggestions
						&& section.suggestions.length
					"
					class="decidesk-transcription__suggestions">
					<li
						v-for="(sug, sIdx) in section.suggestions"
						:key="sIdx"
						:data-testid="
							sug.unverified
								? 'suggestion-unverified'
								: 'suggestion-matched'
						">
						{{ sug.title }}
						<span
							v-if="sug.unverified"
							class="decidesk-transcription__unverified">
							{{ t('decidesk', 'unverified — no recorded outcome') }}
						</span>
						<span v-else class="decidesk-transcription__verified">
							{{ t('decidesk', 'linked to recorded outcome') }}
						</span>
					</li>
				</ul>

				<div
					v-if="!section.discarded"
					class="decidesk-transcription__section-actions">
					<NcButton
						variant="tertiary"
						:data-testid="'draft-section-discard'"
						@click="discardSection(section)">
						{{ t('decidesk', 'Discard section') }}
					</NcButton>
				</div>
			</div>
		</section>

		<TranscriptionConsentModal
			v-if="consentOpen"
			@confirm="attachWithConsent"
			@close="consentOpen = false" />
	</div>
</template>

<script>
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect, NcTextArea } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import TranscriptionConsentModal from '../../modals/TranscriptionConsentModal.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingTranscriptionTab',
	components: {
		CnNoteCard,
		CnStatusBadge,
		NcButton,
		NcSelect,
		NcTextArea,
		TranscriptionConsentModal,
	},
	inject: {
		/**
		 * CnDetailPage's reactive `{ objectId, object, register, schema }`
		 * holder.
		 *
		 * This panel is declared in the manifest as a `type: "custom"` body
		 * widget on MeetingDetail, and CnDetailPage's `widget-<id>` slot binds
		 * ONLY `{ item, widget }` — never the page's object id. Without this
		 * injection the `objectId` prop is empty on that mount path, `refresh()`
		 * returns before calling the transcription API, `loaded` stays false and
		 * neither the provider-unavailable note nor the transcript ever renders.
		 * This is the same holder the declarative `@objectId` filter token
		 * resolves against.
		 */
		cnObjectContext: { default: null },
	},
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loaded: false,
			working: false,
			error: '',
			providerAvailable: false,
			aiAvailable: false,
			sourceOptions: [],
			selectedSource: null,
			transcript: null,
			agendaTitles: {},
			draft: null,
			consentOpen: false,
		}
	},
	computed: {
		/**
		 * The meeting this panel acts on: the explicit `objectId` prop when
		 * mounted directly, otherwise the id CnDetailPage provides on
		 * `cnObjectContext` (manifest body-widget mount, where no id prop is
		 * bound).
		 *
		 * @return {string} The meeting UUID, or '' when not resolvable.
		 * @spec openspec/specs/meeting-transcription/spec.md
		 */
		resolvedObjectId() {
			if (this.objectId) {
				return String(this.objectId)
			}
			const context = this.cnObjectContext
			// Vue unwraps an injected ref for the Options API, but the compat
			// build can hand back the ref itself — accept both shapes.
			const value =
				context && typeof context === 'object' && 'value' in context
					? context.value
					: context
			return value && value.objectId ? String(value.objectId) : ''
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		statusColors() {
			return {
				pending: 'primary',
				processing: 'warning',
				done: 'success',
				failed: 'error',
			}
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		statusLabel() {
			const map = {
				pending: this.t('decidesk', 'Pending'),
				processing: this.t('decidesk', 'Processing'),
				done: this.t('decidesk', 'Done'),
				failed: this.t('decidesk', 'Failed'),
			}
			return map[this.transcript?.status] || this.transcript?.status || ''
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		canTranscribe() {
			return (
				this.providerAvailable
				&& this.transcript
				&& ['pending', 'failed'].includes(this.transcript.status)
			)
		},
		/**
		 * Segments grouped per agenda item, with an unassigned group last.
		 *
		 * @return {Array<object>} Ordered groups.
		 * @spec openspec/specs/meeting-transcription/spec.md
		 */
		groupedSegments() {
			const segments = (this.transcript && this.transcript.segments) || []
			const groups = {}
			const order = []
			segments.forEach((seg) => {
				const key = seg.agendaItem || 'unassigned'
				if (!groups[key]) {
					groups[key] = {
						key: key === 'unassigned' ? 'unassigned' : 'item',
						id: key,
						title:
							key === 'unassigned'
								? this.t('decidesk', 'Unassigned')
								: this.agendaTitles[key]
									|| this.t('decidesk', 'Agenda item'),
						segments: [],
					}
					order.push(key)
				}
				groups[key].segments.push(seg)
			})
			// Unassigned last.
			return order
				.sort(
					(a, b) =>
						(a === 'unassigned' ? 1 : 0) - (b === 'unassigned' ? 1 : 0),
				)
				.map((k) => groups[k])
		},
	},
	watch: {
		resolvedObjectId: {
			immediate: true,
			handler() {
				this.refresh()
			},
		},
	},
	methods: {
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async refresh() {
			if (!this.resolvedObjectId) return
			this.error = ''
			try {
				const data = await this.callApi(
					`/meetings/${this.resolvedObjectId}/transcription/sources`,
					{},
					'GET',
				)
				this.providerAvailable = !!data.providerAvailable
				this.aiAvailable = !!data.aiAvailable
				this.sourceOptions = (data.sources || []).map((s) => ({
					label: `${s.name} (${s.type === 'talk-recording' ? this.t('decidesk', 'Talk recording') : this.t('decidesk', 'File')})`,
					...s,
				}))
				await this.loadExistingTranscript()
				await this.loadAgendaTitles()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidesk', 'Could not load transcription state.')
			} finally {
				this.loaded = true
			}
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async loadExistingTranscript() {
			try {
				const store = ensureRelationType('transcript')
				const items = await store.fetchCollection('transcript', {
					meeting: this.resolvedObjectId,
					_limit: 1,
				})
				this.transcript = (items && items[0]) || null
			} catch (e) {
				this.transcript = null
			}
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async loadAgendaTitles() {
			try {
				const store = ensureRelationType('agenda-item')
				const items = await store.fetchCollection('agenda-item', {
					meeting: this.resolvedObjectId,
					_limit: 200,
				})
				const map = {}
				;(items || []).forEach((it) => {
					map[it.id || it.uuid] = it.title || it.name
				})
				this.agendaTitles = map
			} catch (e) {
				this.agendaTitles = {}
			}
		},
		/**
		 * Set the selected recording source.
		 *
		 * @param {object} value The selected source option.
		 * @spec openspec/specs/meeting-transcription/spec.md
		 */
		onSelectSource(value) {
			this.selectedSource = value
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		openConsent() {
			if (!this.selectedSource) return
			this.consentOpen = true
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async attachWithConsent() {
			this.consentOpen = false
			if (!this.selectedSource) return
			this.working = true
			this.error = ''
			try {
				this.transcript = await this.callApi(
					`/meetings/${this.resolvedObjectId}/transcription/attach`,
					{
						sourceType: this.selectedSource.type,
						sourcePath: this.selectedSource.path,
						consent: true,
					},
				)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidesk', 'Could not attach the recording.')
			} finally {
				this.working = false
			}
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async transcribe() {
			if (!this.transcript) return
			this.working = true
			this.error = ''
			try {
				await this.callApi(`/transcripts/${this.transcriptId()}/transcribe`)
				if (this.transcript) this.transcript.status = 'processing'
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidesk', 'Could not start transcription.')
			} finally {
				this.working = false
			}
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async realign() {
			if (!this.transcript) return
			this.working = true
			this.error = ''
			try {
				this.transcript = await this.callApi(
					`/transcripts/${this.transcriptId()}/re-align`,
				)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidesk', 'Could not re-align the transcript.')
			} finally {
				this.working = false
			}
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		async generateDraft() {
			if (!this.transcript) return
			this.working = true
			this.error = ''
			try {
				const draft = await this.callApi(
					`/transcripts/${this.transcriptId()}/generate-draft`,
				)
				this.draft = {
					...draft,
					sections: (draft.sections || []).map((s) => ({
						...s,
						discarded: false,
						edited: false,
					})),
				}
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Could not generate the draft.')
			} finally {
				this.working = false
			}
		},
		/**
		 * Discard a generated section (removes its AI content + marker).
		 *
		 * @param {object} section The draft section.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		discardSection(section) {
			section.discarded = true
		},
		/**
		 * Mark a generated section as edited by the secretary.
		 *
		 * @param {object} section The draft section.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		markEdited(section) {
			section.edited = true
		},
		/** @spec openspec/specs/meeting-transcription/spec.md */
		transcriptId() {
			return this.transcript && (this.transcript.id || this.transcript.uuid)
		},
		/**
		 * Call the decidesk transcription API.
		 *
		 * @param {string} path Path under /apps/decidesk/api.
		 * @param {object} body JSON body.
		 * @param {string} method HTTP method.
		 * @return {Promise<object>} Parsed response.
		 * @spec openspec/specs/meeting-transcription/spec.md
		 */
		async callApi(path, body = {}, method = 'POST') {
			const opts = {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: window.OC?.requestToken,
				},
			}
			if (method !== 'GET') opts.body = JSON.stringify(body)
			const response = await fetch(
				generateUrl(`/apps/decidesk/api${path}`),
				opts,
			)
			const data = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(
					data.message || this.t('decidesk', 'The action failed.'),
				)
			}
			return data
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-transcription__attach,
.decidesk-transcription__status,
.decidesk-transcription__draft {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidesk-transcription__status {
	flex-direction: row;
	align-items: center;
	flex-wrap: wrap;
}

.decidesk-transcription__label {
	font-weight: bold;
}

.decidesk-transcription__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.decidesk-transcription__group {
	border-inline-start: 3px solid var(--color-primary-element);
	padding-inline-start: var(--default-grid-baseline);
	margin-block-end: var(--default-grid-baseline);
}

.decidesk-transcription__group-title {
	margin: 0 0 4px;
}

.decidesk-transcription__segment {
	margin: 2px 0;
}

.decidesk-transcription__section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: var(--default-grid-baseline);
	margin-block-end: var(--default-grid-baseline);
}

.decidesk-transcription__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.decidesk-transcription__ai-marker {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	border-radius: var(--border-radius);
	padding: 0 8px;
	font-size: 0.8em;
	font-weight: bold;
}

.decidesk-transcription__unverified {
	color: var(--color-warning-text, var(--color-warning));
	font-style: italic;
	margin-inline-start: 6px;
}

.decidesk-transcription__verified {
	color: var(--color-success-text, var(--color-success));
	margin-inline-start: 6px;
}
</style>
