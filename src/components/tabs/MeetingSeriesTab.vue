<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: recurring series for a Meeting.

 Configures a recurrence pattern (frequency / interval / until /
 exceptions per meeting-series REQ-MSR-001), previews the instance
 count via the frontend mirror of MeetingSeriesService::expandPattern,
 generates the series through POST /api/meetings/{id}/series, and lists
 the existing instances of the meeting's series.

 @spec openspec/specs/meeting-management/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--series" data-testid="meeting-series-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Recurring series') }}
				<span v-if="!loading && instances.length > 0" class="decidesk-tab__count">({{ instances.length }})</span>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Series error')">
			{{ error }}
		</CnNoteCard>

		<CnNoteCard
			v-if="successMessage"
			type="success"
			:title="t('decidesk', 'Series generated')">
			{{ successMessage }}
		</CnNoteCard>

		<!-- Pattern form -->
		<form class="series-form" data-testid="series-pattern-form" @submit.prevent="generate">
			<NcSelect
				v-model="frequency"
				:input-label="t('decidesk', 'Frequency')"
				:options="frequencyOptions"
				:clearable="false" />
			<NcTextField
				v-model="interval"
				type="number"
				min="1"
				:label="t('decidesk', 'Interval')"
				:placeholder="t('decidesk', 'e.g. 1')" />
			<NcTextField
				v-model="until"
				type="date"
				:label="t('decidesk', 'Until (inclusive)')" />
			<NcTextField
				v-model="exceptions"
				:label="t('decidesk', 'Exception dates')"
				:placeholder="t('decidesk', 'Comma-separated dates, e.g. 2026-07-14, 2026-08-11')" />

			<p
				v-if="preview.error === null"
				class="series-form__preview"
				data-testid="series-preview"
				aria-live="polite">
				{{ t('decidesk', 'This pattern creates {n} meeting(s).', { n: preview.dates.length }) }}
				<span v-if="preview.truncated">
					{{ t('decidesk', 'The series is capped at 52 instances.') }}
				</span>
			</p>

			<NcButton
				variant="primary"
				type="submit"
				data-testid="series-generate"
				:disabled="generating || preview.error !== null || preview.dates.length === 0"
				:aria-label="t('decidesk', 'Generate meeting series')">
				{{ generating ? t('decidesk', 'Generating…') : t('decidesk', 'Generate series') }}
			</NcButton>
		</form>

		<!-- Existing instances -->
		<div v-if="meeting && meeting.series" class="series-instances">
			<h4>{{ t('decidesk', 'Instances in series {series}', { series: meeting.series }) }}</h4>
			<CnDataTable
				:columns="columns"
				:rows="instances"
				:loading="loading"
				row-key="id"
				:empty-text="t('decidesk', 'No other meetings in this series yet.')"
				:loading-text="t('decidesk', 'Loading series instances…')" />
		</div>
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { ensureRelationType } from './useRelationStore.js'
import { expandRecurrence } from '../../services/agendaRules.js'

export default {
	name: 'MeetingSeriesTab',
	components: { CnDataTable, CnNoteCard, NcButton, NcSelect, NcTextField },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			generating: false,
			error: '',
			successMessage: '',
			meeting: null,
			instances: [],
			frequency: 'monthly',
			interval: '1',
			until: '',
			exceptions: '',
		}
	},
	computed: {
		/** @spec openspec/specs/meeting-management/spec.md */
		frequencyOptions() {
			return ['daily', 'weekly', 'monthly']
		},

		/** @spec openspec/specs/meeting-management/spec.md */
		columns() {
			return [
				{ key: 'scheduledDate', label: this.t('decidesk', 'Scheduled date') },
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'lifecycle', label: this.t('decidesk', 'Lifecycle') },
			]
		},

		/** @spec openspec/specs/meeting-management/spec.md */
		pattern() {
			return {
				frequency: this.frequency,
				interval: Number(this.interval) || 1,
				until: this.until,
				exceptions: this.exceptions
					.split(',')
					.map(d => d.trim())
					.filter(d => d !== ''),
			}
		},

		/** @spec openspec/specs/meeting-management/spec.md */
		preview() {
			if (!this.meeting || !this.meeting.scheduledDate) {
				return { dates: [], truncated: false, error: 'date' }
			}
			return expandRecurrence(this.meeting.scheduledDate, this.pattern)
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/meeting-management/spec.md */
			handler() { this.refresh() },
		},
	},
	methods: {
		/** @spec openspec/specs/meeting-management/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('meeting')
				this.meeting = await store.fetchObject('meeting', this.objectId)
				await this.loadInstances()
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load the meeting.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/meeting-management/spec.md */
		async loadInstances() {
			if (!this.meeting?.series) {
				this.instances = []
				return
			}
			const store = ensureRelationType('meeting')
			const rows = await store.fetchCollection('meeting', {
				series: this.meeting.series,
				_limit: 100,
			})
			this.instances = (rows || [])
				.slice()
				.sort((a, b) => String(a.scheduledDate || '').localeCompare(String(b.scheduledDate || '')))
		},

		/** @spec openspec/specs/meeting-management/spec.md */
		async generate() {
			this.generating = true
			this.error = ''
			this.successMessage = ''
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/meetings/${this.objectId}/series`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ pattern: this.pattern }),
					},
				)
				const payload = await response.json()
				if (!response.ok || payload?.success === false) {
					this.error = payload?.message || this.t('decidesk', 'Series generation failed.')
					return
				}
				this.successMessage = payload.message
				await this.refresh()
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Series generation failed.')
			} finally {
				this.generating = false
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

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
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

.series-form {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.series-form__preview {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.series-instances h4 {
	margin: var(--default-grid-baseline) 0;
}
</style>
