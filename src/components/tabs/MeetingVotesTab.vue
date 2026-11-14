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

 @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#requirement-per-meeting-stemmingen-overview-tab
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
import { generateUrl } from '@nextcloud/router'
import { breakdownUrl, factionRows } from '../../utils/voteBreakdown.js'
import { ensureRelationType } from './useRelationStore.js'

/**
 * The vote counts, result and time of a round, a missing count read as 0.
 *
 * @param {object} round The voting round
 * @return {{votesFor: number, votesAgainst: number, votesAbstain: number, result: string, timestamp: string}} The tally.
 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
 */
function roundTally(round) {
	return {
		...voteCounts(round),
		result: round.result || '',
		timestamp: round.closedAt || round.openedAt || '',
	}
}

/**
 * The vote counts of a round, a missing count read as 0.
 *
 * @param {object} round The voting round
 * @return {{votesFor: number, votesAgainst: number, votesAbstain: number}} The counts.
 * @spec openspec/changes/refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
 */
function voteCounts(round) {
	return {
		votesFor: round.votesFor ?? 0,
		votesAgainst: round.votesAgainst ?? 0,
		votesAbstain: round.votesAbstain ?? 0,
	}
}

/**
 * The per-faction breakdown of a round, or null when the server refuses it.
 *
 * @param {string} roundId The voting round id
 * @return {Promise<object|null>} The breakdown.
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
async function fetchBreakdown(roundId) {
	const response = await fetch(generateUrl(breakdownUrl(roundId)), {
		headers: { requesttoken: window.OC?.requestToken },
	})
	if (!response.ok) return null
	return response.json()
}

/**
 * The id of an object, or its uuid when it has no id.
 *
 * @param {object|null} object An agenda item or motion
 * @return {string|undefined} The id.
 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
 */
function idOf(object) {
	return object?.id || object?.uuid
}

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
		/** @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
		columns() {
			return [
				{ key: 'motionTitle', label: this.t('decidiq', 'Motion') },
				{ key: 'motionType', label: this.t('decidiq', 'Type') },
				{ key: 'votesFor', label: this.t('decidiq', 'For') },
				{ key: 'votesAgainst', label: this.t('decidiq', 'Against') },
				{ key: 'votesAbstain', label: this.t('decidiq', 'Abstain') },
				{ key: 'factions', label: this.t('decidiq', 'Per faction') },
				{ key: 'result', label: this.t('decidiq', 'Result') },
				{ key: 'timestamp', label: this.t('decidiq', 'When') },
			]
		},

		/** @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
		resultColors() {
			return { adopted: 'success', rejected: 'error', tied: 'warning' }
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
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
		/** @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				this.rounds = await this.meetingRounds()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load voting overview.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Every voting round of the meeting, one row per round.
		 *
		 * @return {Promise<Array<object>>} The rows.
		 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
		 */
		async meetingRounds() {
			const agendaItems = await ensureRelationType(
				'agenda-item',
			).fetchCollection('agenda-item', {
				meeting: this.objectId,
				_limit: 200,
			})
			if (!Array.isArray(agendaItems) || !agendaItems.length) {
				return []
			}
			const collected = []
			for (const item of agendaItems) {
				const itemId = idOf(item)
				if (itemId) collected.push(...(await this.agendaItemRounds(itemId)))
			}
			return collected
		},

		/**
		 * The rows of every motion on one agenda item.
		 *
		 * @param {string} itemId The agenda item id
		 * @return {Promise<Array<object>>} The rows.
		 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
		 */
		async agendaItemRounds(itemId) {
			const motions = await ensureRelationType('motion').fetchCollection(
				'motion',
				{
					decisionType: 'motion',
					agendaItem: itemId,
					_limit: 100,
				},
			)
			const collected = []
			for (const motion of motions || []) {
				if (idOf(motion)) {
					collected.push(...(await this.motionRounds(motion)))
				}
			}
			return collected
		},

		/**
		 * The rows of one motion's voting rounds.
		 *
		 * @param {object} motion The motion
		 * @return {Promise<Array<object>>} The rows.
		 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
		 */
		async motionRounds(motion) {
			const rounds = await ensureRelationType('voting-round').fetchCollection(
				'voting-round',
				{
					motion: idOf(motion),
					_limit: 50,
				},
			)
			const rows = []
			for (const round of rounds || []) {
				rows.push(await this.roundRow(motion, round))
			}
			return rows
		},

		/**
		 * One table row for a round of a motion.
		 *
		 * @param {object} motion The motion
		 * @param {object} round The voting round
		 * @return {Promise<object>} The row.
		 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
		 */
		async roundRow(motion, round) {
			const roundId = round.id || round.uuid
			const { timestamp, ...tally } = roundTally(round)
			return {
				id: roundId,
				motionId: idOf(motion),
				motionTitle: motion.title || this.t('decidiq', 'Motion'),
				motionType: motion.motionType || '',
				...tally,
				factions: await this.factionSummary(roundId),
				timestamp,
			}
		},

		/**
		 * One line per faction for a round, or a note for a secret round.
		 *
		 * @param {string} roundId The voting round id
		 * @return {Promise<string>}
		 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
		 */
		async factionSummary(roundId) {
			try {
				const breakdown = await fetchBreakdown(roundId)
				if (breakdown === null) return ''
				if (breakdown.secret) return this.t('decidiq', 'Secret vote')
				return factionRows(breakdown)
					.map((row) => this.factionLine(row))
					.join('; ')
			} catch {
				return ''
			}
		},

		/**
		 * One faction's line of a round's summary.
		 *
		 * @param {object} row The faction row
		 * @return {string}
		 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
		 */
		factionLine(row) {
			return this.t(
				'decidiq',
				'{faction}: {for} for, {against} against, {abstain} abstain',
				{
					faction: row.faction,
					for: row.for,
					against: row.against,
					abstain: row.abstain,
				},
			)
		},

		/**
		 * Deep-link to MotionDetail with the votes tab requested via query.
		 *
		 * @param {object} row Round row (must carry `motionId`).
		 * @spec openspec/changes/archive/2026-06-10-refactor-decidesk-ia-alignment/specs.md#scenario-listing-voting-rounds-for-the-meeting
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

<style scoped src="./relationTab.css"></style>

<style scoped>
.decidiq-tab__loading {
	color: var(--color-text-maxcontrast);
}
</style>
