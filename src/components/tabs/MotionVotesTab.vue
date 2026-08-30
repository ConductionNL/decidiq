<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: votes cast on a Motion (post-vote audit surface).

 Posture: read-only. Votes are cast through the LiveMeeting view, not
 here — this tab walks the motion → voting-round → vote chain and
 lists each cast vote with caster + value + timestamp. The deleted
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
			</div>
		</div>

		<CnDataTable
			:columns="columns"
			:rows="votes"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No votes recorded for this motion yet.')"
			:loadingText="t('decidiq', 'Loading votes…')">
			<template #column-caster="{ row }">
				{{ casterDisplayName(row) }}
			</template>
			<template #column-value="{ value }">
				<CnStatusBadge v-if="value" :label="value" :colorMap="voteColors" />
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
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
			casterById: Object.create(null),
		}
	},

	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'caster', label: this.t('decidiq', 'Voter') },
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

				const voteStore = ensureRelationType('vote')
				const all = []
				for (const round of this.rounds) {
					const list = await voteStore.fetchCollection('vote', {
						votingRound: round.id,
						_limit: 200,
					})
					if (Array.isArray(list)) all.push(...list)
				}
				this.votes = all
				await this.hydrateCasters(all)
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Failed to load votes.')
			} finally {
				this.loading = false
			}
		},

		// Resolve raw `caster` foreign keys to participant display names.
		// Builds a per-id lookup once per refresh; falls back to the raw
		// value (or "—") when a participant can't be resolved (deleted /
		// not in this register).
		/**
		 * @param votes
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async hydrateCasters(votes) {
			const ids = new Set()
			for (const v of votes) {
				const id = v && (v.caster?.id || v.caster)
				if (id != null && id !== '') ids.add(String(id))
			}
			if (!ids.size) {
				this.casterById = Object.create(null)
				return
			}
			try {
				const partStore = ensureRelationType('participant')
				const list = await partStore.fetchCollection('participant', {
					_limit: Math.max(50, ids.size),
				})
				const map = Object.create(null)
				for (const p of list || []) {
					if (!p) continue
					const key = String(p.id ?? p.uuid ?? '')
					if (!key) continue
					map[key] =
						p.displayName || p.name || p.fullName || p.email || key
				}
				this.casterById = map
			} catch {
				// Non-fatal: column falls back to the raw caster value.
				this.casterById = Object.create(null)
			}
		},

		/**
		 * @param row
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		casterDisplayName(row) {
			const raw = row && (row.caster?.id || row.caster)
			if (raw == null || raw === '') return '—'
			const key = String(raw)
			return this.casterById[key] || key
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
