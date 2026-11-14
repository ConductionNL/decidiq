<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Theme detail-view timeline (publication-theme-pages, REQ-THP-002): every
 meeting, agenda item, decision, commitment and long-term agenda item linked
 to the theme, oldest first, and for staff a mark on what is not published.
 Only published entries reach the resident's page (REQ-THP-003).

 @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
-->
<template>
	<div class="decidiq-theme-timeline" data-testid="theme-timeline">
		<NcLoadingIcon v-if="loading" :size="20" />
		<CnNoteCard
			v-else-if="error"
			type="error"
			data-testid="theme-timeline-error"
			:title="t('decidiq', 'Could not load the timeline')">
			{{ error }}
		</CnNoteCard>
		<p v-else-if="rows.length === 0" class="decidiq-theme-timeline__empty" data-testid="theme-timeline-empty">
			{{ t('decidiq', 'Nothing is linked to this theme yet.') }}
		</p>
		<ol v-else class="decidiq-theme-timeline__list">
			<li
				v-for="row in rows"
				:key="row.key"
				class="decidiq-theme-timeline__entry"
				data-testid="theme-timeline-entry">
				<span class="decidiq-theme-timeline__date">{{ row.date || t('decidiq', 'No date') }}</span>
				<span class="decidiq-theme-timeline__kind">{{ kindLabel(row.kind) }}</span>
				<span class="decidiq-theme-timeline__title">{{ row.title }}</span>
				<CnStatusBadge
					v-if="!row.published"
					data-testid="theme-timeline-unpublished"
					:label="t('decidiq', 'Not published')"
					variant="warning" />
			</li>
		</ol>
	</div>
</template>

<script>
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcLoadingIcon } from '@nextcloud/vue'
import { timelineRows, timelineUrl } from '../../utils/themes.js'

export default {
	name: 'ThemeTimelineTab',
	components: { CnNoteCard, CnStatusBadge, NcLoadingIcon },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
		}
	},

	watch: {
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the staff timeline of the theme.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const response = await fetch(timelineUrl(String(this.objectId)), {
					headers: { Accept: 'application/json', requesttoken: window.OC?.requestToken },
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error(data.message || '')
				}
				this.rows = timelineRows(data.entries)
			} catch (e) {
				this.rows = []
				this.error = e.message || this.t('decidiq', 'The timeline could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The label of an entry's kind.
		 *
		 * @param {string} kind The kind.
		 * @return {string} The label.
		 */
		kindLabel(kind) {
			switch (kind) {
			case 'meeting': return this.t('decidiq', 'Meeting')
			case 'agendaItem': return this.t('decidiq', 'Agenda item')
			case 'decision': return this.t('decidiq', 'Decision')
			case 'commitment': return this.t('decidiq', 'Commitment')
			case 'plannedAgendaItem': return this.t('decidiq', 'Long-term agenda item')
			default: return kind
			}
		},
	},
}
</script>

<style scoped>
.decidiq-theme-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidiq-theme-timeline__entry {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidiq-theme-timeline__date {
	min-width: 7em;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

.decidiq-theme-timeline__kind {
	min-width: 9em;
	color: var(--color-text-maxcontrast);
}

.decidiq-theme-timeline__title {
	flex: 1 1 12em;
}

.decidiq-theme-timeline__empty {
	color: var(--color-text-maxcontrast);
}
</style>
