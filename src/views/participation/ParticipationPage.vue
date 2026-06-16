<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Authenticated citizen + staff participation view (citizen-participation).

 Citizens: react to open consultations, submit proposals during a budget
 round's submission phase, and cast advisory votes on validated proposals
 during the voting phase.

 Staff: trigger lifecycle transitions and publish PII-free results.

 Plain reads use useObjectStore (the OpenRegister object API per ADR-022);
 every state-changing operation goes through the participation ACTION
 endpoints in services/participationApi.js.

 @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
-->
<template>
	<div class="participation-page" data-testid="participation-page">
		<header class="participation-page__header">
			<h2>{{ t('decidesk', 'Citizen participation') }}</h2>
			<p>{{ t('decidesk', 'Open consultations and participatory budget rounds for your governance body.') }}</p>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<template v-else>
			<!-- Open consultations -->
			<section class="participation-page__section" data-testid="participation-consultations">
				<h3>{{ t('decidesk', 'Open consultations') }}</h3>
				<NcEmptyContent
					v-if="openConsultations.length === 0"
					:name="t('decidesk', 'No open consultations')" />
				<div
					v-for="c in openConsultations"
					:key="c.id"
					class="participation-card"
					data-testid="consultation-card">
					<h4>{{ c.title }}</h4>
					<p>{{ c.description }}</p>
					<NcTextArea
						:value.sync="reactionDrafts[c.id]"
						data-testid="reaction-input"
						:label="t('decidesk', 'Your reaction')"
						resize="vertical" />
					<NcButton
						type="primary"
						data-testid="reaction-submit"
						:disabled="!(reactionDrafts[c.id] || '').trim()"
						@click="sendReaction(c)">
						{{ t('decidesk', 'Submit reaction') }}
					</NcButton>
					<div v-if="isStaff" class="participation-card__staff">
						<NcButton data-testid="consultation-close" @click="transitionC(c, 'closed')">
							{{ t('decidesk', 'Close') }}
						</NcButton>
						<NcButton data-testid="consultation-publish" @click="publishC(c)">
							{{ t('decidesk', 'Publish results') }}
						</NcButton>
					</div>
				</div>
			</section>

			<!-- Budget rounds -->
			<section class="participation-page__section" data-testid="participation-budgets">
				<h3>{{ t('decidesk', 'Participatory budget rounds') }}</h3>
				<NcEmptyContent
					v-if="budgetRounds.length === 0"
					:name="t('decidesk', 'No active budget rounds')" />
				<div
					v-for="b in budgetRounds"
					:key="b.id"
					class="participation-card"
					data-testid="budget-card">
					<h4>{{ b.name }}</h4>
					<p>{{ formatAmount(b.totalAmount, b.currency) }} · {{ phaseLabel(b.status) }}</p>

					<!-- Proposal submission (submission phase) -->
					<form
						v-if="b.status === 'submission'"
						class="participation-card__form"
						data-testid="proposal-form"
						@submit.prevent="sendProposal(b)">
						<NcTextField
							:value.sync="proposalDrafts[b.id].title"
							:label="t('decidesk', 'Proposal title')" />
						<NcTextArea
							:value.sync="proposalDrafts[b.id].description"
							:label="t('decidesk', 'Description')"
							resize="vertical" />
						<NcTextField
							type="number"
							:value.sync="proposalDrafts[b.id].amount"
							:label="t('decidesk', 'Requested amount')" />
						<NcButton
							type="primary"
							native-type="submit"
							data-testid="proposal-submit"
							:disabled="!(proposalDrafts[b.id].title || '').trim()">
							{{ t('decidesk', 'Submit proposal') }}
						</NcButton>
					</form>

					<!-- Voting cards (voting phase) -->
					<div v-else-if="b.status === 'voting'" data-testid="voting-cards">
						<NcEmptyContent
							v-if="(votableProposals[b.id] || []).length === 0"
							:name="t('decidesk', 'No proposals to vote on yet')" />
						<div
							v-for="p in (votableProposals[b.id] || [])"
							:key="p.id"
							class="voting-card"
							data-testid="voting-card">
							<span class="voting-card__title">{{ p.title }}</span>
							<span class="voting-card__tally">{{ p.votesFor || 0 }} / {{ p.votesAgainst || 0 }}</span>
							<NcButton type="success" data-testid="vote-voor" @click="vote(p, 'voor')">
								{{ t('decidesk', 'For') }}
							</NcButton>
							<NcButton type="error" data-testid="vote-tegen" @click="vote(p, 'tegen')">
								{{ t('decidesk', 'Against') }}
							</NcButton>
						</div>
					</div>

					<div v-if="isStaff" class="participation-card__staff">
						<NcButton
							v-if="nextBudgetPhase(b.status)"
							data-testid="budget-advance"
							@click="transitionB(b, nextBudgetPhase(b.status))">
							{{ t('decidesk', 'Advance phase') }}
						</NcButton>
						<NcButton
							v-if="b.status === 'closed'"
							data-testid="budget-publish"
							@click="publishB(b)">
							{{ t('decidesk', 'Publish allocation') }}
						</NcButton>
					</div>
				</div>
			</section>

			<NcNoteCard
				v-if="catalogWarning"
				type="warning"
				data-testid="opencatalogi-warning">
				{{ catalogWarning }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useObjectStore, useSettingsStore } from '../../store/store.js'
import {
	castAdvisoryVote,
	publishBudgetResults,
	publishConsultationResults,
	submitProposal,
	submitReaction,
	transitionBudgetRound,
	transitionConsultation,
} from '../../services/participationApi.js'

const NEXT_BUDGET_PHASE = {
	draft: 'submission',
	submission: 'voting',
	voting: 'tallying',
	tallying: 'closed',
}

export default {
	name: 'ParticipationPage',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcTextArea, NcTextField },
	data() {
		return {
			loading: true,
			isStaff: false,
			openConsultations: [],
			budgetRounds: [],
			reactionDrafts: {},
			proposalDrafts: {},
			votableProposals: {},
			catalogWarning: '',
		}
	},
	/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
	async mounted() {
		try {
			const settingsStore = useSettingsStore()
			await settingsStore.fetchSettings()
			// UI hint only — every staff action is independently authorized
			// server-side by ParticipationController::requireStaff().
			this.isStaff = !!(settingsStore.getSettings && settingsStore.getSettings.isAdmin)
		} catch (e) {
			this.isStaff = false
		}
		this.load()
	},
	methods: {
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async load() {
			this.loading = true
			try {
				const store = useObjectStore()
				const consultations = await store.fetchCollection('public-consultation', { status: 'open', _limit: 100 })
				this.openConsultations = (Array.isArray(consultations) ? consultations : []).filter((c) => c.status === 'open')
				for (const c of this.openConsultations) {
					this.$set(this.reactionDrafts, c.id, '')
				}

				const rounds = await store.fetchCollection('participatory-budget', { _limit: 100 })
				this.budgetRounds = (Array.isArray(rounds) ? rounds : []).filter((b) => ['submission', 'voting', 'closed'].includes(b.status))
				for (const b of this.budgetRounds) {
					this.$set(this.proposalDrafts, b.id, { title: '', description: '', amount: '' })
					if (b.status === 'voting') {
						await this.loadVotable(b)
					}
				}
			} catch (e) {
				showError(t('decidesk', 'Could not load participation rounds'))
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async loadVotable(round) {
			try {
				const store = useObjectStore()
				const proposals = await store.fetchCollection('budget-proposal', { status: 'validated', _limit: 200 })
				this.$set(this.votableProposals, round.id, (Array.isArray(proposals) ? proposals : []).filter((p) => p.status === 'validated'))
			} catch (e) {
				this.$set(this.votableProposals, round.id, [])
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async sendReaction(consultation) {
			try {
				await submitReaction(consultation.id, this.reactionDrafts[consultation.id])
				showSuccess(t('decidesk', 'Your reaction was submitted for moderation'))
				this.$set(this.reactionDrafts, consultation.id, '')
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Could not submit your reaction')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async sendProposal(round) {
			const draft = this.proposalDrafts[round.id]
			try {
				await submitProposal(round.id, {
					title: draft.title,
					description: draft.description,
					amount: Number(draft.amount) || 0,
				})
				showSuccess(t('decidesk', 'Proposal submitted'))
				this.$set(this.proposalDrafts, round.id, { title: '', description: '', amount: '' })
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Could not submit your proposal')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async vote(proposal, value) {
			try {
				const result = await castAdvisoryVote(proposal.id, value)
				proposal.votesFor = result.votesFor
				proposal.votesAgainst = result.votesAgainst
				showSuccess(t('decidesk', 'Your vote was recorded'))
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Could not record your vote')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async transitionC(consultation, status) {
			try {
				await transitionConsultation(consultation.id, status)
				showSuccess(t('decidesk', 'Consultation updated'))
				await this.load()
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Transition failed')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async transitionB(round, status) {
			try {
				await transitionBudgetRound(round.id, status)
				showSuccess(t('decidesk', 'Budget round updated'))
				await this.load()
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Transition failed')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async publishC(consultation) {
			try {
				const result = await publishConsultationResults(consultation.id, '')
				this.reportPublication(result)
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Publication failed')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		async publishB(round) {
			try {
				const result = await publishBudgetResults(round.id)
				this.reportPublication(result)
			} catch (e) {
				showError(this.apiError(e, t('decidesk', 'Publication failed')))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		reportPublication(result) {
			if (result && result.warning) {
				this.catalogWarning = result.warning
				showSuccess(t('decidesk', 'Results published (with a warning)'))
			} else {
				this.catalogWarning = ''
				showSuccess(t('decidesk', 'Results published'))
			}
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		nextBudgetPhase(status) {
			return NEXT_BUDGET_PHASE[status] || ''
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		phaseLabel(status) {
			const labels = {
				draft: t('decidesk', 'Draft'),
				submission: t('decidesk', 'Submission phase'),
				voting: t('decidesk', 'Voting phase'),
				tallying: t('decidesk', 'Tallying'),
				closed: t('decidesk', 'Closed'),
			}
			return labels[status] || status
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		formatAmount(amount, currency) {
			const value = Number(amount) || 0
			return `${value.toLocaleString()} ${currency || 'EUR'}`
		},
		/** @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md */
		apiError(e, fallback) {
			return (e && e.response && e.response.data && e.response.data.message) ? e.response.data.message : fallback
		},
	},
}
</script>

<style scoped>
.participation-page {
	padding: 16px;
	max-width: 860px;
}

.participation-page__section {
	margin-top: 24px;
}

.participation-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-bottom: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.participation-card__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.participation-card__staff {
	display: flex;
	gap: 8px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.voting-card {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}

.voting-card__title {
	flex: 1;
}

.voting-card__tally {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
