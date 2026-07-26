<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: participants attending a Meeting.

 Posture: add-existing / remove. Each participant carries a `meetings`
 array; this tab filters participants whose `meetings` array contains
 the current meeting id, lets you link existing participants, and
 removes them from the meeting (without deleting the participant).
-->
<template>
	<div class="decidesk-tab decidesk-tab--participants" data-testid="meeting-participants-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Participants') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<NcButton
				variant="primary"
				data-testid="meeting-participants-add"
				:aria-label="t('decidesk', 'Add participant')"
				@click="addDialogOpen = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Add participant') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load participants')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No participants linked to this meeting yet.')"
			:loading-text="t('decidesk', 'Loading participants…')">
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<NcDialog
			v-if="addDialogOpen"
			:name="t('decidesk', 'Add participant')"
			@closing="addDialogOpen = false">
			<template #default>
				<p>{{ t('decidesk', 'Pick a participant to link to this meeting.') }}</p>
				<div v-if="loadingCandidates" class="decidesk-tab__loading">
					{{ t('decidesk', 'Loading participants…') }}
				</div>
				<ul v-else-if="candidates.length" class="decidesk-tab__list">
					<li v-for="cand in candidates" :key="cand.id">
						<NcButton @click="linkParticipant(cand)">
							{{ candidateLabel(cand) }}
						</NcButton>
					</li>
				</ul>
				<p v-else class="decidesk-tab__empty">
					{{ t('decidesk', 'No more participants available to link.') }}
				</p>
			</template>
		</NcDialog>

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget"
			name-field="displayName"
			:dialog-title="t('decidesk', 'Remove from meeting')"
			@confirm="confirmRemove"
			@close="removeTarget = null" />
	</div>
</template>

<script>
import { CnDataTable, CnDeleteDialog, CnNoteCard, CnRowActions } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingParticipantsTab',
	components: { CnDataTable, CnDeleteDialog, CnNoteCard, CnRowActions, NcButton, NcDialog, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			addDialogOpen: false,
			loadingCandidates: false,
			candidates: [],
			removeTarget: null,
		}
	},
	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'displayName', label: this.t('decidesk', 'Name') },
				{ key: 'role', label: this.t('decidesk', 'Role') },
				{ key: 'party', label: this.t('decidesk', 'Party') },
			]
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidesk', 'Remove from meeting'),
					icon: LinkOff,
					destructive: true,
					handler: (row) => { this.removeTarget = { ...row } },
				},
			]
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() { this.refresh() },
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		addDialogOpen(open) {
			if (open) this.loadCandidates()
		},
	},
	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		hasMeeting(participant, meetingId) {
			const list = participant?.meetings
			if (!Array.isArray(list)) return false
			return list.some(m => (typeof m === 'object' ? (m.id || m.uuid) : m) === meetingId)
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('participant')
				// OpenRegister stores `meetings` as an array; fetch a generous
				// page and filter client-side. Server-side array-contains
				// filtering varies by backend version.
				const items = await store.fetchCollection('participant', {
					meetings: this.objectId,
					_limit: 200,
				})
				const filtered = (items || []).filter(p => this.hasMeeting(p, this.objectId))
				this.rows = filtered.length ? filtered : (items || [])
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load participants.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		candidateLabel(p) {
			return p.displayName || p.name || p.id
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async loadCandidates() {
			this.loadingCandidates = true
			try {
				const store = ensureRelationType('participant')
				const items = await store.fetchCollection('participant', { _limit: 200 })
				this.candidates = (items || []).filter(p => !this.hasMeeting(p, this.objectId))
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async linkParticipant(participant) {
			const store = ensureRelationType('participant')
			const meetings = Array.isArray(participant.meetings) ? participant.meetings.slice() : []
			meetings.push(this.objectId)
			await store.saveObject('participant', { ...participant, meetings })
			this.addDialogOpen = false
			this.refresh()
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmRemove() {
			const store = ensureRelationType('participant')
			const target = this.removeTarget
			const meetings = (Array.isArray(target.meetings) ? target.meetings : [])
				.filter(m => (typeof m === 'object' ? (m.id || m.uuid) : m) !== this.objectId)
			try {
				await store.saveObject('participant', { ...target, meetings })
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({ error: e?.message || this.t('decidesk', 'Remove failed.') })
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
.decidesk-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.decidesk-tab__empty,
.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
