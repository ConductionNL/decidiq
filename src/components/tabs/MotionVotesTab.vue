<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: votes cast on a Motion (post-vote audit surface).

 Posture: read-only. Votes are cast through the LiveMeeting view, not
 here. This tab lists the motion's voting rounds and, per round, asks the
 server for its breakdown (GET /api/voting-rounds/{id}/breakdown): each
 member's vote by name and a line per faction with its counts. A secret
 round shows its totals only (vot-03). The deleted
 MotionDetail.vue likewise didn't author votes from the detail page;
 the VotingRoundPanel component drove vote casting only when a round
 was open inside a meeting context, which is now LiveMeeting-only.
-->
<template>
	<div class="decidiq-tab decidiq-tab--votes" data-testid="motion-votes-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Votes') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ votes.length }})</span
				>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load votes')">
			{{ error }}
		</CnNoteCard>

		<div v-if="rounds.length" class="decidiq-tab__rounds">
			<div v-for="round in rounds" :key="round.id" class="decidiq-tab__round">
				<header class="decidiq-tab__round-header">
					<strong>{{
						round.votingMethod || t('decidiq', 'Voting round')
					}}</strong>
					<CnStatusBadge
						v-if="round.result"
						:label="round.result"
						:colorMap="roundColors" />
				</header>
				<p v-if="round.votesFor != null" class="decidiq-tab__round-tally">
					{{
						t(
							'decidiq',
							'For: {for} — Against: {against} — Abstain: {abstain}',
							{
								for: round.votesFor || 0,
								against: round.votesAgainst || 0,
								abstain: round.votesAbstain || 0,
							},
						)
					}}
				</p>
				<p
					v-if="breakdowns[round.id] && breakdowns[round.id].secret"
					class="decidiq-tab__round-secret">
					{{ t('decidiq', 'Secret vote: only the totals are shown.') }}
				</p>
				<table
					v-else-if="factionRows(breakdowns[round.id]).length"
					class="decidiq-tab__factions"
					data-testid="motion-votes-factions">
					<thead>
						<tr>
							<th scope="col">{{ t('decidiq', 'Faction') }}</th>
							<th scope="col">{{ t('decidiq', 'For') }}</th>
							<th scope="col">{{ t('decidiq', 'Against') }}</th>
							<th scope="col">{{ t('decidiq', 'Abstain') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="faction in factionRows(breakdowns[round.id])"
							:key="faction.id">
							<th scope="row">{{ faction.faction }}</th>
							<td>{{ faction.for }}</td>
							<td>{{ faction.against }}</td>
							<td>{{ faction.abstain }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<CnDataTable
			:columns="columns"
			:rows="votes"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No votes recorded for this motion yet.')"
			:loadingText="t('decidiq', 'Loading votes…')">
			<template #column-voter="{ row }">
				{{
					row.castBy
						? t('decidiq', '{name} (cast by {proxy})', {
								name: row.voter,
								proxy: row.castBy,
							})
						: row.voter
				}}
			</template>
			<template #column-value="{ row, value }">
				<span v-if="row.rankingText" data-testid="vote-ranking">{{
					row.rankingText
				}}</span>
				<CnStatusBadge
					v-else-if="value"
					:label="value"
					:colorMap="voteColors" />
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { breakdownUrl, factionRows, memberRows } from '../../utils/voteBreakdown.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MotionVotesTab',
	components: { CnDataTable, CnNoteCard, CnStatusBadge },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rounds: [],
			votes: [],
			breakdowns: {},
		}
	},

	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'voter', label: this.t('decidiq', 'Voter') },
				{ key: 'faction', label: this.t('decidiq', 'Faction') },
				{ key: 'value', label: this.t('decidiq', 'Vote') },
				{ key: 'castAt', label: this.t('decidiq', 'Cast at') },
			]
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		voteColors() {
			return { for: 'success', against: 'error', abstain: 'default' }
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		roundColors() {
			return { adopted: 'success', rejected: 'error', tied: 'warning' }
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const roundStore = ensureRelationType('voting-round')
				const rounds = await roundStore.fetchCollection('voting-round', {
					motion: this.objectId,
					_limit: 50,
				})
				this.rounds = rounds || []

				if (!this.rounds.length) {
					this.votes = []
					return
				}

				const all = []
				const breakdowns = {}
				for (const round of this.rounds) {
					const breakdown = await this.fetchBreakdown(round.id)
					breakdowns[round.id] = breakdown
					all.push(...memberRows(breakdown, round))
				}
				this.breakdowns = breakdowns
				this.votes = all
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Failed to load votes.')
			} finally {
				this.loading = false
			}
		},

		factionRows,
		/**
		 * The breakdown of one round, or null when it cannot be read.
		 *
		 * @param {string} roundId The voting round id
		 * @return {Promise<object|null>}
		 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
		 */
		async fetchBreakdown(roundId) {
			const response = await fetch(generateUrl(breakdownUrl(roundId)), {
				headers: { requesttoken: window.OC?.requestToken },
			})
			if (!response.ok) return null
			return response.json()
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

.decidiq-tab__factions {
	border-collapse: collapse;
	margin-top: 4px;
}

.decidiq-tab__factions th,
.decidiq-tab__factions td {
	padding: 2px 8px;
	text-align: start;
}

.decidiq-tab__round-secret {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.decidiq-tab__rounds {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decidiq-tab__round {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.decidiq-tab__round-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.decidiq-tab__round-tally {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
