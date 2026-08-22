<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: voting results behind a Decision.

 Read-only audit surface: walks the decision → motion → voting-round →
 vote chain (the decision's `motion` relation) and renders the
 for/against/abstain tally per round plus the individual votes table —
 the MotionVotesTab pattern, anchored on the decision side.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--decision-votes"
		data-testid="decision-voting-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Voting results') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ votes.length }})</span
				>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load voting results')">
			{{ error }}
		</CnNoteCard>

		<p
			v-if="!loading && !error && !motionId"
			class="decidiq-tab__none"
			data-testid="decision-voting-none">
			{{
				t(
					'decidiq',
					'No motion is linked to this decision, so there are no voting results.',
				)
			}}
		</p>

		<div v-if="rounds.length" class="decidiq-tab__rounds">
			<div
				v-for="round in rounds"
				:key="round.id"
				class="decidiq-tab__round"
				data-testid="decision-voting-round">
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
			v-if="motionId"
			:columns="columns"
			:rows="votes"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No votes recorded for this decision yet.')"
			:loadingText="t('decidiq', 'Loading voting results…')">
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
	name: 'DecisionVotingTab',
	components: { CnDataTable, CnNoteCard, CnStatusBadge },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			motionId: '',
			rounds: [],
			votes: [],
		}
	},

	computed: {
		/** @spec openspec/specs/decision-management/spec.md */
		columns() {
			return [
				{ key: 'caster', label: this.t('decidiq', 'Voter') },
				{ key: 'value', label: this.t('decidiq', 'Vote') },
				{ key: 'castAt', label: this.t('decidiq', 'Cast at') },
			]
		},

		/** @spec openspec/specs/decision-management/spec.md */
		voteColors() {
			return { for: 'success', against: 'error', abstain: 'default' }
		},

		/** @spec openspec/specs/decision-management/spec.md */
		roundColors() {
			return { adopted: 'success', rejected: 'error', tied: 'warning' }
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/decision-management/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/decision-management/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const decisionStore = ensureRelationType('decision')
				const decision = await decisionStore.fetchObject(
					'decision',
					this.objectId,
				)
				const rawMotion =
					decision && (decision.motion?.id || decision.motion)
				this.motionId =
					rawMotion != null && rawMotion !== '' ? String(rawMotion) : ''

				if (!this.motionId) {
					this.rounds = []
					this.votes = []
					return
				}

				const roundStore = ensureRelationType('voting-round')
				const rounds = await roundStore.fetchCollection('voting-round', {
					motion: this.motionId,
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
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load voting results.')
			} finally {
				this.loading = false
			}
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

.decidiq-tab__none {
	margin: 0;
	color: var(--color-text-maxcontrast);
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
