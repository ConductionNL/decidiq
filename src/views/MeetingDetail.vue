<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/p2-agenda-management/tasks.md#task-3
-->
<template>
	<div class="meeting-detail">
		<header class="meeting-detail__header">
			<h2>{{ meeting.title || t('decidesk', 'Meeting') }}</h2>
			<div class="meeting-detail__meta">
				<span v-if="meeting.scheduledDate">{{ meeting.scheduledDate }}</span>
				<span v-if="meeting.location"> — {{ meeting.location }}</span>
			</div>
		</header>

		<!-- Publication controls -->
		<div class="meeting-detail__publish-section">
			<button v-if="isChairOrSecretary && !isPublished"
				class="meeting-detail__btn meeting-detail__btn--primary"
				:disabled="agendaItems.length === 0"
				@click="publishAgenda">
				{{ t('decidesk', 'Publish agenda') }}
			</button>
			<button v-if="isChairOrSecretary && isPublished"
				class="meeting-detail__btn"
				@click="reviseAgenda">
				{{ t('decidesk', 'Revise agenda') }}
			</button>
			<button v-if="agendaItems.length > 0"
				class="meeting-detail__btn"
				@click="showExport = true">
				{{ t('decidesk', 'Export') }}
			</button>
			<button v-if="meeting.lifecycle === 'opened'"
				class="meeting-detail__btn meeting-detail__btn--live"
				@click="goToLiveMeeting">
				{{ t('decidesk', 'Live meeting') }}
			</button>
		</div>

		<!-- Validation error -->
		<p v-if="publishError" class="meeting-detail__error">
			{{ publishError }}
		</p>

		<!-- Agenda builder -->
		<AgendaBuilder
			:items="agendaItems"
			:meeting-id="meetingId"
			:is-chair="isChairOrSecretary"
			:can-propose="canPropose"
			@items-updated="fetchAgendaItems"
			@add-item="showAddDialog = true"
			@add-recurring="showRecurringDialog = true"
			@propose-item="showProposeDialog = true"
			@assign-spokesperson="openSpokespersonDialog" />

		<!-- COI summary for chair -->
		<div v-if="isChairOrSecretary" class="meeting-detail__coi-summary">
			<h3>{{ t('decidesk', 'Conflict of interest declarations') }}</h3>
			<div v-if="coiItems.length === 0" class="meeting-detail__empty">
				{{ t('decidesk', 'No conflict of interest declarations submitted') }}
			</div>
			<ul v-else class="meeting-detail__coi-list">
				<li v-for="coiItem in coiItems" :key="coiItem.id || coiItem.uuid">
					<strong>{{ coiItem.title }}</strong>:
					<span v-for="(note, idx) in getCoiNotes(coiItem)" :key="idx">
						{{ note.title.replace('COI: ', '') }}{{ idx < getCoiNotes(coiItem).length - 1 ? ', ' : '' }}
					</span>
				</li>
			</ul>
		</div>

		<!-- Export dialog -->
		<CnMassExportDialog
			v-if="showExport"
			ref="exportDialog"
			:dialog-title="t('decidesk', 'Export agenda')"
			:description="t('decidesk', 'Download the agenda as CSV with columns: Number, Title, Type, Duration, Spokesperson, Attachments.')"
			:formats="[{ id: 'csv', label: 'CSV (.csv)' }]"
			default-format="csv"
			:success-text="t('decidesk', 'Export completed')"
			:cancel-label="t('decidesk', 'Cancel')"
			:close-label="t('decidesk', 'Close')"
			:confirm-label="t('decidesk', 'Download CSV')"
			@confirm="onExportConfirm"
			@close="showExport = false" />
	</div>
</template>

<script>
import AgendaBuilder from '../components/AgendaBuilder.vue'
import CnMassExportDialog from '@conduction/nextcloud-vue/src/components/CnMassExportDialog/CnMassExportDialog.vue'
import { useAgendaStore } from '../store/modules/agenda.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * Meeting detail view with agenda builder and publication controls.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-3
 */
export default {
	name: 'MeetingDetail',

	components: {
		AgendaBuilder,
		CnMassExportDialog,
	},

	data() {
		return {
			meeting: {},
			agendaItems: [],
			publishError: '',
			showExport: false,
			showAddDialog: false,
			showRecurringDialog: false,
			showProposeDialog: false,
			currentUserRole: 'none',
		}
	},

	computed: {
		meetingId() {
			return this.$route.params.id
		},

		/**
		 * Derives publication status from the fetched meeting object so it
		 * persists across page navigations rather than being session-only.
		 */
		isPublished() {
			return this.meeting.agendaPublished === true || this.meeting.lifecycle === 'published'
		},

		isChairOrSecretary() {
			return this.currentUserRole === 'chair'
				|| this.currentUserRole === 'voorzitter'
				|| this.currentUserRole === 'secretary'
				|| this.currentUserRole === 'secretaris'
		},

		canPropose() {
			return this.meeting.lifecycle === 'scheduled' || this.meeting.lifecycle === 'opened'
		},

		coiItems() {
			return this.agendaItems.filter(
				(item) => (item.notes || []).some((n) => (n.title || '').startsWith('COI:')),
			)
		},
	},

	async created() {
		await this.fetchMeeting()
		await this.fetchAgendaItems()
		const agendaStore = useAgendaStore()
		this.currentUserRole = await agendaStore.fetchUserRole(this.meetingId)
	},

	methods: {
		async fetchMeeting() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchObjects('meeting', { id: this.meetingId })
			if (Array.isArray(results) && results.length > 0) {
				this.meeting = results[0]
			} else if (results && !Array.isArray(results)) {
				this.meeting = results
			}
		},

		async fetchAgendaItems() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchObjects('agendaItem', { meeting: this.meetingId })
			this.agendaItems = Array.isArray(results) ? results : []
		},

		async publishAgenda() {
			this.publishError = ''
			if (this.agendaItems.length === 0) {
				this.publishError = this.t('decidesk', 'An agenda must contain at least one agenda item')
				return
			}

			const agendaStore = useAgendaStore()
			const result = await agendaStore.publishAgenda(this.meetingId)
			if (result && result.success) {
				// Reflect published state in the meeting object so isPublished stays true on reload.
				this.meeting = { ...this.meeting, agendaPublished: true }
			} else {
				this.publishError = (result && result.error) || this.t('decidesk', 'Publication failed')
			}
		},

		async reviseAgenda() {
			// Clear published flag on the backend by saving the updated meeting state.
			const objectStore = useObjectStore()
			await objectStore.saveObject({ id: this.meeting.id || this.meeting.uuid, agendaPublished: false })
			this.meeting = { ...this.meeting, agendaPublished: false }
		},

		goToLiveMeeting() {
			this.$router.push({ name: 'LiveMeeting', params: { id: this.meetingId } })
		},

		getCoiNotes(item) {
			return (item.notes || []).filter((n) => (n.title || '').startsWith('COI:'))
		},

		openSpokespersonDialog(item) {
			// Spokesperson assignment handled via relation mechanism.
			this.$emit('assign-spokesperson', item)
		},

		/**
		 * Wrap a value in RFC 4180 CSV quoting: double-quote the value and
		 * escape any embedded double-quotes.  Applied to all string columns
		 * to protect against injection and commas in field values.
		 *
		 * @param {*} value - Field value to quote
		 * @return {string} RFC 4180 quoted string
		 */
		csvField(value) {
			return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"'
		},

		onExportConfirm() {
			try {
				this.exportCsv()
				this.$refs.exportDialog.setResult({ success: true })
			} catch (err) {
				this.$refs.exportDialog.setResult({ error: err.message })
			}
		},

		exportCsv() {
			const typeLabels = {
				informational: this.t('decidesk', 'Informational'),
				discussion: this.t('decidesk', 'Discussion'),
				decision: this.t('decidesk', 'Decision'),
			}
			const sorted = [...this.agendaItems].sort((a, b) => (a.orderNumber || 0) - (b.orderNumber || 0))
			const header = [
				this.csvField(this.t('decidesk', 'Number')),
				this.csvField(this.t('decidesk', 'Title')),
				this.csvField(this.t('decidesk', 'Type')),
				this.csvField(this.t('decidesk', 'Duration (min)')),
				this.csvField(this.t('decidesk', 'Spokesperson')),
				this.csvField(this.t('decidesk', 'Attachments')),
			]
			const rows = sorted.map((item) => {
				const typeLabel = typeLabels[item.itemType] || item.itemType || ''
				const spokesperson = this.getSpokesperson(item)
				// Apply RFC 4180 quoting to all string fields to prevent injection and
				// handle commas in names, titles, or type labels.
				return [
					this.csvField(item.orderNumber || ''),
					this.csvField(item.title || ''),
					this.csvField(typeLabel),
					this.csvField(item.estimatedDuration || ''),
					this.csvField(spokesperson),
					this.csvField((item.files || []).length),
				].join(',')
			})

			const csv = [header.join(','), ...rows].join('\n')
			const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = (this.meeting.title || 'agenda') + '.csv'
			link.click()
		},

		getSpokesperson(item) {
			const relations = item.relations || []
			const spokesRel = relations.find((r) => r.name === 'spokesperson' || r.type === 'spokesperson')
			return spokesRel ? (spokesRel.displayName || spokesRel.title || '') : ''
		},
	},
}
</script>

<style scoped>
.meeting-detail {
	padding: 16px;
	max-width: 1200px;
}

.meeting-detail__header {
	margin-bottom: 16px;
}

.meeting-detail__header h2 {
	margin: 0 0 4px;
	font-size: 22px;
	font-weight: 600;
}

.meeting-detail__meta {
	color: var(--color-text-maxcontrast);
}

.meeting-detail__publish-section {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.meeting-detail__btn {
	padding: 8px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 14px;
}

.meeting-detail__btn:hover {
	background: var(--color-background-hover);
}

.meeting-detail__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.meeting-detail__btn--primary:hover {
	background: var(--color-primary-element-hover);
}

.meeting-detail__btn--primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.meeting-detail__btn--live {
	background: var(--color-success-element-light, var(--color-success));
}

.meeting-detail__error {
	color: var(--color-error);
	margin: 0 0 12px;
}

.meeting-detail__coi-summary {
	margin-top: 24px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.meeting-detail__coi-summary h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
}

.meeting-detail__empty {
	color: var(--color-text-maxcontrast);
}

.meeting-detail__coi-list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}

.meeting-detail__dialog-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.meeting-detail__dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 480px;
	width: 100%;
}

.meeting-detail__dialog h3 {
	margin: 0 0 12px;
}

.meeting-detail__dialog-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
	justify-content: flex-end;
}
</style>
