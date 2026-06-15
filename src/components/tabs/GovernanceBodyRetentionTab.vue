<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: per-body transcript/recording retention policy
 (meeting-transcription-ai-minutes).

 The chair/secretary configures what happens to a meeting's recording and raw
 transcript after the minutes are approved: keep everything, delete only the
 recording, or delete both (the privacy-forward default). The window (days
 after approval) is configurable. The TranscriptRetentionJob enforces this
 server-side. The default initial values come from server-provided initial
 state (loadState), not DOM data-attributes.

 @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--retention" data-testid="body-retention-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Recording retention') }}
			</h3>
		</div>

		<CnNoteCard v-if="error" type="error" :title="t('decidesk', 'Could not save retention policy')">
			{{ error }}
		</CnNoteCard>

		<p class="decidesk-tab__hint">
			{{ t('decidesk', 'Choose what happens to this body\'s meeting recordings and raw transcripts after the minutes are approved. The approved minutes always remain the official record.') }}
		</p>

		<NcSelect
			input-id="retention-policy-select"
			data-testid="retention-policy-select"
			:input-label="t('decidesk', 'Retention policy')"
			:options="policyOptions"
			:value="selectedPolicy"
			label="label"
			@input="onPolicy" />

		<NcTextField
			type="number"
			data-testid="retention-days"
			:label="t('decidesk', 'Days after approval')"
			:value.sync="days" />

		<NcButton
			type="primary"
			data-testid="retention-save"
			:disabled="saving || !selectedPolicy"
			@click="save">
			{{ t('decidesk', 'Save retention policy') }}
		</NcButton>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'GovernanceBodyRetentionTab',
	components: { CnNoteCard, NcButton, NcSelect, NcTextField },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		// Server-provided defaults via initial state (never DOM data-attributes).
		const defaults = this.readDefaults()
		return {
			error: '',
			saving: false,
			days: defaults.days,
			selectedPolicy: null,
			defaultPolicy: defaults.policy,
		}
	},
	computed: {
		/** @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md */
		policyOptions() {
			return [
				{ id: 'keep', label: this.t('decidesk', 'Keep everything') },
				{ id: 'delete-recording', label: this.t('decidesk', 'Delete recording only') },
				{ id: 'delete-both', label: this.t('decidesk', 'Delete recording and transcript') },
			]
		},
	},
	watch: {
		objectId: { immediate: true, handler() { this.load() } },
	},
	methods: {
		/**
		 * Read default retention values from server-provided initial state.
		 *
		 * @return {{policy: string, days: number}} Defaults.
		 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
		 */
		readDefaults() {
			let policy = 'delete-both'
			let days = 30
			try {
				policy = loadState('decidesk', 'transcriptRetentionDefaultPolicy', 'delete-both')
				days = Number(loadState('decidesk', 'transcriptRetentionDefaultDays', 30)) || 30
			} catch (e) {
				// Initial state not provided (e.g. in-app context) — use safe defaults.
			}
			return { policy, days }
		},
		/** @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md */
		async load() {
			if (!this.objectId) return
			this.error = ''
			try {
				const store = ensureRelationType('governance-body')
				const items = await store.fetchCollection('governance-body', { id: this.objectId, _limit: 1 })
				const body = (items && items[0]) || null
				const current = (body && body.transcriptRetentionPolicy) || this.defaultPolicy
				this.selectedPolicy = this.policyOptions.find((o) => o.id === current) || this.policyOptions[2]
				if (body && body.transcriptRetentionDays) this.days = body.transcriptRetentionDays
			} catch (e) {
				// Fall back to the default policy selection.
				this.selectedPolicy = this.policyOptions.find((o) => o.id === this.defaultPolicy) || this.policyOptions[2]
			}
		},
		/**
		 * Set the selected retention policy.
		 *
		 * @param {object} value The selected policy option.
		 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
		 */
		onPolicy(value) {
			this.selectedPolicy = value
		},
		/** @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md */
		async save() {
			if (!this.objectId || !this.selectedPolicy) return
			this.saving = true
			this.error = ''
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/governance-bodies/${this.objectId}/retention-config`),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: window.OC?.requestToken,
						},
						body: JSON.stringify({ policy: this.selectedPolicy.id, days: Number(this.days) }),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error(data.message || this.t('decidesk', 'Could not save the retention policy.'))
				}
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Could not save the retention policy.')
			} finally {
				this.saving = false
			}
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

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
