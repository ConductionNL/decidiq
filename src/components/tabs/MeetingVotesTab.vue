<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: voting overview (stemmingen) for a Meeting.

 Posture: read-only — consistent with MotionVotesTab.vue. Votes are
 cast exclusively through the LiveMeeting view during the meeting; this
 tab is the post-meeting aggregate that lets a secretary scan every
 voting round + tally for a single meeting without re-entering
 LiveMeeting. It walks meeting → agenda-item → motion → voting-round
 (the canonical relation chain used by AgendaMotionsTab and
 MotionVotesTab) and groups the rounds by their parent motion. No
 create / edit / cast action is offered.

 @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#requirement-per-meeting-stemmingen-overview-tab
-->
<template>
	<div class="decidiq-tab decidiq-tab--votes" data-testid="meeting-votes-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Votes') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rounds.length }})</span
				>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load voting overview')">
			{{ error }}
		</CnNoteCard>

		<CnNoteCard
			v-else-if="!loading && !rounds.length"
			type="info"
			:title="t('decidiq', 'No voting recorded for this meeting')"
			data-testid="meeting-votes-empty">
			{{ t('decidiq', 'No voting recorded for this meeting.') }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Loading voting overview…') }}
		</p>

		<CnDataTable
			v-else
			:columns="columns"
			:rows="rounds"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No voting recorded for this meeting.')"
			@rowClick="openMotion">
			<template #column-result="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="value"
					:colorMap="resultColors" />
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingVotesTab',
	components: { CnDataTable, CnNoteCard, CnStatusBadge },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			// Each row: { id, motionId, motionTitle, motionType,
			//   votesFor, votesAgainst, votesAbstain, result, timestamp }.
			rounds: [],
		}
	},

	computed: {
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
		columns() {
			return [
				{ key: 'motionTitle', label: this.t('decidiq', 'Motion') },
				{ key: 'motionType', label: this.t('decidiq', 'Type') },
				{ key: 'votesFor', label: this.t('decidiq', 'For') },
				{ key: 'votesAgainst', label: this.t('decidiq', 'Against') },
				{ key: 'votesAbstain', label: this.t('decidiq', 'Abstain') },
				{ key: 'result', label: this.t('decidiq', 'Result') },
				{ key: 'timestamp', label: this.t('decidiq', 'When') },
			]
		},

		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
		resultColors() {
			return { adopted: 'success', rejected: 'error', tied: 'warning' }
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		// Read-only aggregate. Walks meeting → agenda-item → motion →
		// voting-round (the chain AgendaMotionsTab/MotionVotesTab use);
		// no direct meeting link exists on voting-round. Vote authoring
		// stays exclusively in LiveMeetingView.
		/** @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const agendaStore = ensureRelationType('agenda-item')
				const agendaItems = await agendaStore.fetchCollection(
					'agenda-item',
					{
						meeting: this.objectId,
						_limit: 200,
					},
				)
				if (!Array.isArray(agendaItems) || !agendaItems.length) {
					this.rounds = []
					return
				}

				const motionStore = ensureRelationType('motion')
				const roundStore = ensureRelationType('voting-round')
				const collected = []
				for (const item of agendaItems) {
					const itemId = item?.id || item?.uuid
					if (!itemId) continue
					const motions = await motionStore.fetchCollection('motion', {
						decisionType: 'motion',
						agendaItem: itemId,
						_limit: 100,
					})
					for (const motion of motions || []) {
						const motionId = motion?.id || motion?.uuid
						if (!motionId) continue
						const rounds = await roundStore.fetchCollection(
							'voting-round',
							{
								motion: motionId,
								_limit: 50,
							},
						)
						for (const round of rounds || []) {
							collected.push({
								id: round.id || round.uuid,
								motionId,
								motionTitle:
									motion.title || this.t('decidiq', 'Motion'),
								motionType: motion.motionType || '',
								votesFor: round.votesFor ?? 0,
								votesAgainst: round.votesAgainst ?? 0,
								votesAbstain: round.votesAbstain ?? 0,
								result: round.result || '',
								timestamp: round.closedAt || round.openedAt || '',
							})
						}
					}
				}
				this.rounds = collected
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load voting overview.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Deep-link to MotionDetail with the votes tab requested via query.
		 *
		 * @param {object} row Round row (must carry `motionId`).
		 * @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
		 */
		openMotion(row) {
			if (!row || !row.motionId) return
			this.$router.push({
				name: 'MotionDetail',
				params: { id: row.motionId },
				query: { tab: 'votes' },
			})
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.decidiq-tab__loading {
	color: var(--color-text-maxcontrast);
}
</style>
