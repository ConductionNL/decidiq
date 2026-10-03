<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VotingRoundPanel component — embedded in MotionDetail and AmendmentDetail.
 Shows the current open voting round, vote casting, live tally, proxy controls, and results.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
-->
<template>
	<CnDetailCard :title="t('decidiq', 'Voting round')">
		<!-- Loading state -->
		<p v-if="loading" class="decidiq-empty">
			{{ t('decidiq', 'Loading…') }}
		</p>

		<!-- No round yet — chair can open one -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.3 -->
		<template v-else-if="!currentRound">
			<p class="decidiq-empty">
				{{ t('decidiq', 'No active voting round.') }}
			</p>
			<NcButton
				v-if="motionLifecycle === 'deliberating' && permissions.canOpen"
				variant="primary"
				:disabled="!meetingId"
				:title="
					!meetingId
						? t(
								'decidiq',
								'No meeting linked — the voting round cannot be opened',
							)
						: undefined
				"
				@click="showOpenRoundDialog = true">
				{{ t('decidiq', 'Open voting round') }}
			</NcButton>

			<!-- Open round dialog -->
			<div
				v-if="showOpenRoundDialog"
				class="decidiq-dialog"
				role="dialog"
				:aria-label="t('decidiq', 'Open voting round')">
				<h3>{{ t('decidiq', 'Open voting round') }}</h3>
				<label for="votingMethod">{{ t('decidiq', 'Voting method') }}</label>
				<select id="votingMethod" v-model="newRound.votingMethod">
					<option value="for-against-abstain">
						{{ t('decidiq', 'For / Against / Abstain') }}
					</option>
					<option value="show-of-hands">
						{{ t('decidiq', 'Show of hands') }}
					</option>
					<option value="weighted">
						{{ t('decidiq', 'Weighted vote') }}
					</option>
					<option value="ranked-choice">
						{{ t('decidiq', 'Ranked preference (Borda count)') }}
					</option>
				</select>
				<!-- Options of a ranked round (REQ-PRF-001, issue #1419) -->
				<!-- @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice -->
				<fieldset
					v-if="newRound.votingMethod === 'ranked-choice'"
					class="decidiq-ranked-options"
					data-testid="ranked-options-editor">
					<legend>{{ t('decidiq', 'Options to rank') }}</legend>
					<div
						v-for="(option, index) in newRound.options"
						:key="index"
						class="decidiq-ranked-options__row">
						<NcTextField
							v-model="option.label"
							:label="
								t('decidiq', 'Option {number}', {
									number: index + 1,
								})
							"
							:data-testid="`ranked-option-${index}`" />
						<NcButton
							variant="tertiary"
							:aria-label="
								t('decidiq', 'Remove option {number}', {
									number: index + 1,
								})
							"
							:disabled="newRound.options.length <= 2"
							@click="newRound.options.splice(index, 1)">
							{{ t('decidiq', 'Remove') }}
						</NcButton>
					</div>
					<NcButton
						variant="secondary"
						data-testid="ranked-option-add"
						:disabled="newRound.options.length >= 20"
						@click="newRound.options.push({ label: '' })">
						{{ t('decidiq', 'Add option') }}
					</NcButton>
				</fieldset>
				<label>
					<input v-model="newRound.isSecret" type="checkbox" />
					{{ t('decidiq', 'Secret ballot') }}
				</label>
				<!-- Configurable voting rules (voting-system spec) -->
				<!-- @spec openspec/specs/voting-system/spec.md -->
				<label for="voteThreshold">{{
					t('decidiq', 'Vote threshold')
				}}</label>
				<select
					id="voteThreshold"
					v-model="newRound.voteThreshold"
					data-testid="vote-threshold-select">
					<option value="">
						{{ t('decidiq', "The body's rule") }}
					</option>
					<option
						v-for="value in voteThresholdOptions"
						:key="value"
						:value="value">
						{{ labels.voteThreshold[value] }}
					</option>
				</select>
				<label for="abstentionHandling">{{
					t('decidiq', 'Abstention handling')
				}}</label>
				<select
					id="abstentionHandling"
					v-model="newRound.abstentionHandling"
					data-testid="abstention-handling-select">
					<option value="">
						{{ t('decidiq', "The body's rule") }}
					</option>
					<option
						v-for="value in abstentionModeOptions"
						:key="value"
						:value="value">
						{{ labels.abstentionHandling[value] }}
					</option>
				</select>
				<label for="tieBreakRule">{{
					t('decidiq', 'Tie-break rule')
				}}</label>
				<select
					id="tieBreakRule"
					v-model="newRound.tieBreakRule"
					data-testid="tie-break-rule-select">
					<option value="">
						{{ t('decidiq', "The body's rule") }}
					</option>
					<option
						v-for="value in openTieBreakRuleOptions"
						:key="value"
						:value="value">
						{{ labels.tieBreakRule[value] }}
					</option>
				</select>
				<p v-if="revoteOfRoundId" class="decidiq-revote-notice">
					{{
						t(
							'decidiq',
							'This round is the single permitted revote of the tied round.',
						)
					}}
				</p>
				<label for="closedAt">{{
					t('decidiq', 'Closing time (optional)')
				}}</label>
				<input
					id="closedAt"
					v-model="newRound.closedAt"
					type="datetime-local" />
				<p v-if="openRoundError" class="decidiq-error" role="alert">
					{{ openRoundError }}
				</p>
				<div class="decidiq-dialog-actions">
					<NcButton
						variant="primary"
						:disabled="openingRound"
						@click="openRound">
						{{ t('decidiq', 'Open') }}
					</NcButton>
					<NcButton @click="showOpenRoundDialog = false">
						{{ t('decidiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</template>

		<!-- Active or closed round -->
		<template v-else>
			<!-- Show-of-hands entry (chair/secretary when round is open) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.6 -->
			<div
				v-if="isRoundOpen && currentRound.votingMethod === 'show-of-hands'"
				class="decidiq-show-of-hands">
				<h4>{{ t('decidiq', 'Save show-of-hands result') }}</h4>
				<label for="showFor">{{ t('decidiq', 'For') }}</label>
				<input
					id="showFor"
					v-model.number="showOfHands.for"
					type="number"
					min="0"
					:aria-label="t('decidiq', 'Votes for')" />
				<label for="showAgainst">{{ t('decidiq', 'Against') }}</label>
				<input
					id="showAgainst"
					v-model.number="showOfHands.against"
					type="number"
					min="0"
					:aria-label="t('decidiq', 'Votes against')" />
				<label for="showAbstain">{{ t('decidiq', 'Abstain') }}</label>
				<input
					id="showAbstain"
					v-model.number="showOfHands.abstain"
					type="number"
					min="0"
					:aria-label="t('decidiq', 'Abstentions')" />
				<NcButton variant="primary" @click="saveShowOfHands">
					{{ t('decidiq', 'Save result') }}
				</NcButton>
			</div>

			<!-- Vote casting buttons -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 -->
			<!-- Ranked ballot (REQ-PRF-002, issue #1419) -->
			<!-- @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting -->
			<template v-if="isRoundOpen && isRankedRound && !voteCast">
				<RankedBallot
					:options="currentRound.options || []"
					:busy="castingRanking"
					@submit="castRanking" />
				<p v-if="castVoteError" class="decidiq-error" role="alert">
					{{ castVoteError }}
				</p>
			</template>

			<div
				v-if="
					isRoundOpen
					&& currentRound.votingMethod !== 'show-of-hands'
					&& !isRankedRound
					&& !voteCast
				"
				class="decidiq-vote-buttons">
				<!-- Proxy notice -->
				<p v-if="activeProxy" class="decidiq-proxy-notice">
					{{
						t('decidiq', 'You are voting on behalf of: {name}', {
							name: activeProxy,
						})
					}}
				</p>
				<NcButton
					variant="primary"
					class="decidiq-vote-btn"
					:aria-label="t('decidiq', 'Vote for')"
					@click="castVote('for')">
					{{ t('decidiq', 'For') }}
				</NcButton>
				<NcButton
					variant="error"
					class="decidiq-vote-btn"
					:aria-label="t('decidiq', 'Vote against')"
					@click="castVote('against')">
					{{ t('decidiq', 'Against') }}
				</NcButton>
				<NcButton
					variant="secondary"
					class="decidiq-vote-btn"
					:aria-label="t('decidiq', 'Abstain')"
					@click="castVote('abstain')">
					{{ t('decidiq', 'Abstain') }}
				</NcButton>
				<p v-if="castVoteError" class="decidiq-error" role="alert">
					{{ castVoteError }}
				</p>
			</div>

			<!-- Vote confirmation message -->
			<p v-if="voteCast" class="decidiq-vote-confirmed" role="status">
				{{ t('decidiq', 'Your vote has been recorded.') }}
			</p>

			<!-- Live tally (chair/secretary see full tally; members see only total count) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.2 -->
			<div v-if="isRoundOpen" class="decidiq-tally">
				<p>
					{{
						t('decidiq', 'Cast: {cast} / {total}', {
							cast: tallyTotal,
							total: eligibleVoters,
						})
					}}
				</p>
				<template v-if="isChairOrSecretary && !isRankedRound">
					<p>
						{{
							t(
								'decidiq',
								'For: {for} — Against: {against} — Abstain: {abstain}',
								{
									for: currentRound.votesFor || 0,
									against: currentRound.votesAgainst || 0,
									abstain: currentRound.votesAbstain || 0,
								},
							)
						}}
					</p>
				</template>
				<!-- Active voting rules + computed base (voting-system spec) -->
				<!-- @spec openspec/specs/voting-system/spec.md -->
				<p class="decidiq-rules" data-testid="active-voting-rules">
					{{ activeRulesSummary }}
				</p>
			</div>

			<!-- Proxy management — proxy grant/revoke is enforced by the backend -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7 -->
			<div class="decidiq-proxy">
				<NcButton
					v-if="!activeProxy"
					variant="secondary"
					@click="showProxyDialog = true">
					{{ t('decidiq', 'Grant proxy') }}
				</NcButton>
				<NcButton v-if="activeProxy" variant="error" @click="revokeProxy">
					{{ t('decidiq', 'Revoke proxy') }}
				</NcButton>
				<div
					v-if="showProxyDialog"
					class="decidiq-dialog"
					role="dialog"
					:aria-label="t('decidiq', 'Grant proxy')">
					<h4>{{ t('decidiq', 'Grant proxy to') }}</h4>
					<!--
						A placeholder is not a label: it is not exposed as the
						input's accessible name, and it disappears the moment the
						user types, so the field loses its only description
						exactly when a screen-reader user is filling it in (WCAG
						3.3.2 Labels or Instructions, 4.1.2 Name Role Value).
						NcTextField carries a real <label for> association, which
						is why it is used here rather than an aria-label bolted
						onto a raw <input> — it is also the idiom the rest of
						this repo already uses.
					-->
					<NcTextField
						v-model="proxyToId"
						:label="t('decidiq', 'Participant UUID')"
						:placeholder="t('decidiq', 'Participant UUID')" />
					<div class="decidiq-dialog-actions">
						<NcButton variant="primary" @click="grantProxy">
							{{ t('decidiq', 'Grant') }}
						</NcButton>
						<NcButton @click="showProxyDialog = false">
							{{ t('decidiq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Close round button (chair/secretary) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.4 -->
			<NcButton
				v-if="isRoundOpen && isChairOrSecretary"
				variant="error"
				@click="confirmCloseRound = true">
				{{ t('decidiq', 'Close voting round') }}
			</NcButton>
			<div v-if="confirmCloseRound" class="decidiq-dialog" role="dialog">
				<p>
					{{
						t(
							'decidiq',
							'Close voting round? {notVoted} of {total} members have not voted yet.',
							{
								notVoted: participantCount - tallyTotal,
								total: participantCount,
							},
						)
					}}
				</p>
				<div class="decidiq-dialog-actions">
					<NcButton variant="error" @click="closeRound">
						{{ t('decidiq', 'Close') }}
					</NcButton>
					<NcButton @click="confirmCloseRound = false">
						{{ t('decidiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<!-- Result display (closed rounds) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.5 -->
			<div v-if="!isRoundOpen && currentRound.result" class="decidiq-result">
				<p>
					<strong>{{ t('decidiq', 'Result:') }}</strong>
					<CnStatusBadge :status="currentRound.result" />
				</p>
				<!-- @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-004-ranked-results-are-displayed-as-a-ranking-table -->
				<RankedResultsCard v-if="isRankedRound" :round="currentRound" />
				<p v-else>
					{{
						t(
							'decidiq',
							'For: {for} — Against: {against} — Abstain: {abstain}',
							{
								for: currentRound.votesFor || 0,
								against: currentRound.votesAgainst || 0,
								abstain: currentRound.votesAbstain || 0,
							},
						)
					}}
				</p>
				<!-- Active rules + computed base shown with the result (voting-system spec) -->
				<!-- @spec openspec/specs/voting-system/spec.md -->
				<p class="decidiq-rules" data-testid="result-voting-rules">
					{{ activeRulesSummary }}
				</p>
				<p
					v-if="currentRound.chairCastingVote"
					class="decidiq-rules"
					data-testid="chair-casting-recorded">
					{{
						t(
							'decidiq',
							"Tie resolved by the chair's casting vote: {value}",
							{ value: currentRound.chairCastingVote },
						)
					}}
				</p>

				<!-- Tied round: chair casting vote (tieBreakRule = chair-decides) -->
				<!-- @spec openspec/specs/voting-system/spec.md -->
				<div
					v-if="
						currentRound.result === 'tied'
						&& activeRules.tieBreakRule === 'chair-decides'
						&& permissions.canCastChairVote
					"
					class="decidiq-chair-casting"
					data-testid="chair-casting-controls">
					<p>
						{{
							t(
								'decidiq',
								'The vote is tied. As chair you must resolve it with a casting vote.',
							)
						}}
					</p>
					<NcButton variant="primary" @click="castChairVote('for')">
						{{ t('decidiq', 'Casting vote: for') }}
					</NcButton>
					<NcButton variant="error" @click="castChairVote('against')">
						{{ t('decidiq', 'Casting vote: against') }}
					</NcButton>
					<p v-if="chairCastingError" class="decidiq-error" role="alert">
						{{ chairCastingError }}
					</p>
				</div>

				<!-- Tied round: single permitted revote (tieBreakRule = revote) -->
				<!-- @spec openspec/specs/voting-system/spec.md -->
				<div
					v-if="
						currentRound.result === 'tied'
						&& activeRules.tieBreakRule === 'revote'
						&& isChairOrSecretary
					"
					class="decidiq-revote"
					data-testid="revote-controls">
					<p>
						{{
							t(
								'decidiq',
								'The vote is tied. The round may be reopened once for a revote.',
							)
						}}
					</p>
					<NcButton variant="primary" @click="startRevote">
						{{ t('decidiq', 'Reopen round (revote)') }}
					</NcButton>
				</div>

				<NcButton
					v-if="isChairOrSecretary"
					variant="secondary"
					@click="publishToOri">
					{{ t('decidiq', 'Publish to ORI') }}
				</NcButton>
				<p v-if="oriStatus" class="decidiq-ori-status">
					{{ oriStatusLabel }}
				</p>
			</div>

			<!-- Revote open dialog (reuses the rule selectors with the tied round's rules prefilled) -->
			<div
				v-if="showOpenRoundDialog && revoteOfRoundId"
				class="decidiq-dialog"
				role="dialog"
				:aria-label="t('decidiq', 'Reopen round (revote)')">
				<h3>{{ t('decidiq', 'Reopen round (revote)') }}</h3>
				<p>
					{{
						t(
							'decidiq',
							'This round is the single permitted revote of the tied round.',
						)
					}}
				</p>
				<p v-if="openRoundError" class="decidiq-error" role="alert">
					{{ openRoundError }}
				</p>
				<div class="decidiq-dialog-actions">
					<NcButton
						variant="primary"
						:disabled="openingRound"
						@click="openRound">
						{{ t('decidiq', 'Open') }}
					</NcButton>
					<NcButton @click="cancelRevote">
						{{ t('decidiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</template>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import RankedBallot from './RankedBallot.vue'
import RankedResultsCard from './RankedResultsCard.vue'
import { useObjectStore } from '../store/store.js'
import { eligibleCount } from '../utils/conflicts.js'
import { matching, relationFilterFor } from '../utils/objectRelations.js'
import {
	chosenRules,
	NO_VOTING_PERMISSIONS,
	readVotingPermissions,
	votingPermissionsPath,
	votingRoundBody,
} from '../utils/votingPermissions.js'
import {
	ABSTENTION_MODES,
	computeBase,
	effectiveRules,
	ruleLabels,
	TIE_BREAK_RULES,
	VOTE_THRESHOLDS,
} from '../utils/votingRules.js'
import { ensureRelationType } from './tabs/useRelationStore.js'

export default {
	name: 'VotingRoundPanel',
	components: {
		CnDetailCard,
		CnStatusBadge,
		NcButton,
		NcTextField,
		RankedBallot,
		RankedResultsCard,
	},

	props: {
		// The subject's id: a motion, or an amendment when subjectType is
		// 'amendment' (the server names it motionId for both).
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		meetingId: { type: String, default: '' },
		/** The agenda item the motion is tabled under: its recusals count too (bod-10) */
		agendaItemId: { type: String, default: '' },
		subjectType: {
			type: String,
			default: 'motion',
			validator: (value) => ['motion', 'amendment'].includes(value),
		},
	},

	/** @spec exclude setup() only wires the shared object + settings store refs; no domain logic */
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},

	data() {
		return {
			loading: false,
			currentRound: null,
			voteCast: false,
			castVoteError: null,
			showOpenRoundDialog: false,
			openingRound: false,
			openRoundError: null,
			confirmCloseRound: false,
			showProxyDialog: false,
			proxyToId: '',
			activeProxy: null,
			oriStatus: null,
			showOfHands: { for: 0, against: 0, abstain: 0 },
			newRound: {
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: '',
				// Empty means "the body's rule": the server fills it from the
				// meeting's governance body (meeting-rules-from-body-and-type).
				voteThreshold: '',
				abstentionHandling: '',
				tieBreakRule: '',
				options: [{ label: '' }, { label: '' }],
			},

			revoteOfRoundId: null,
			castingRanking: false,
			chairCastingError: null,
			pollInterval: null,
			participantCount: 0,
			declarations: [],
			// The server's answer on which controls this user may use
			// (REQ-VCR-002); every control hidden until it arrives.
			permissions: { ...NO_VOTING_PERMISSIONS },
		}
	},

	computed: {
		/**
		 * The members who may vote: participants minus those recused on the
		 * motion or its agenda item (bod-10).
		 *
		 * @return {number}
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
		 */
		eligibleVoters() {
			return eligibleCount(this.participantCount, this.declarations, [
				this.motionId,
				this.agendaItemId,
			])
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 */
		roundId() {
			if (!this.currentRound) return null
			return this.currentRound.id || this.currentRound.uuid || null
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 */
		isRoundOpen() {
			if (!this.currentRound) return false
			if (!this.currentRound.openedAt) return false
			const closedAt = this.currentRound.closedAt
			if (closedAt && new Date(closedAt) <= new Date()) return false
			return true
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.2 */
		tallyTotal() {
			if (!this.currentRound) return 0
			return (
				(this.currentRound.votesFor || 0)
				+ (this.currentRound.votesAgainst || 0)
				+ (this.currentRound.votesAbstain || 0)
			)
		},

		/**
		 * Chair or secretary of this round's meeting, as the server answers it.
		 *
		 * @return {boolean} True when the close, split, revote and publish controls show.
		 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-001-the-meetings-chair-and-secretary-see-the-voting-controls
		 */
		isChairOrSecretary() {
			return this.permissions.canClose
		},

		/** Rule enum option lists for the open-round dialog. @spec openspec/specs/voting-system/spec.md */
		voteThresholdOptions() {
			return VOTE_THRESHOLDS
		},

		/** @spec openspec/specs/voting-system/spec.md */
		abstentionModeOptions() {
			return ABSTENTION_MODES
		},

		/** @spec openspec/specs/voting-system/spec.md */
		tieBreakRuleOptions() {
			return TIE_BREAK_RULES
		},

		/**
		 * Tie-break rules the open dialog offers: a ranked round cannot use
		 * chair-decides, because a casting vote cannot name an option.
		 *
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
		 * @return {Array<string>} The rules
		 */
		openTieBreakRuleOptions() {
			if (this.newRound.votingMethod !== 'ranked-choice') {
				return TIE_BREAK_RULES
			}
			return TIE_BREAK_RULES.filter((rule) => rule !== 'chair-decides')
		},

		/**
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
		 * @return {boolean} Whether the displayed round is a ranked preference round
		 */
		isRankedRound() {
			return (
				this.currentRound?.votingMethod === 'ranked-choice'
				&& (this.currentRound?.options || []).length > 0
			)
		},

		/** Translated labels per rule enum value. @spec openspec/specs/voting-system/spec.md */
		labels() {
			return ruleLabels((text) => this.t('decidiq', text))
		},

		/** Effective rules of the displayed round (defaults applied). @spec openspec/specs/voting-system/spec.md */
		activeRules() {
			return effectiveRules(this.currentRound || {})
		},

		/** Computed calculation base of the displayed round. @spec openspec/specs/voting-system/spec.md */
		computedBase() {
			return computeBase(this.currentRound || {})
		},

		/** One-line summary of active rules + computed base. @spec openspec/specs/voting-system/spec.md */
		activeRulesSummary() {
			const rules = this.activeRules
			return this.t(
				'decidiq',
				'Rules: {threshold} · {abstentions} · {tieBreak} — base: {base}',
				{
					threshold: this.labels.voteThreshold[rules.voteThreshold],
					abstentions:
						this.labels.abstentionHandling[rules.abstentionHandling],
					tieBreak: this.labels.tieBreakRule[rules.tieBreakRule],
					base: this.computedBase,
				},
			)
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.5 */
		oriStatusLabel() {
			const labels = {
				published: this.t('decidiq', 'Published to ORI'),
				pending: this.t('decidiq', 'Publication pending'),
				not_configured: this.t('decidiq', 'ORI not configured'),
			}
			return labels[this.oriStatus] || this.oriStatus
		},
	},

	watch: {
		/** @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-001-the-meetings-chair-and-secretary-see-the-voting-controls */
		meetingId() {
			this.loadPermissions()
		},

		/**
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
		 * @param {string} method The picked voting method
		 */
		'newRound.votingMethod': function (method) {
			if (
				method === 'ranked-choice'
				&& this.newRound.tieBreakRule === 'chair-decides'
			) {
				this.newRound.tieBreakRule = 'rejected'
			}
		},
	},

	/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.2 */
	async mounted() {
		this.loadPermissions()
		await this.fetchCurrentRound()
		// Poll every 5 seconds when round is open.
		this.pollInterval = setInterval(async () => {
			if (this.isRoundOpen) {
				await this.fetchCurrentRound()
			}
		}, 5000)
	},

	/** @spec exclude lifecycle teardown; only clears the polling interval started in mounted() */
	beforeUnmount() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
		}
	},

	methods: {
		/**
		 * Ask the server once per meeting which voting controls this user may
		 * use. Fail closed: an error leaves every control hidden.
		 *
		 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
		 */
		async loadPermissions() {
			try {
				const resp = await fetch(
					OC.generateUrl(votingPermissionsPath(this.meetingId)),
					{ headers: { Accept: 'application/json' } },
				)
				this.permissions = resp.ok
					? readVotingPermissions(await resp.json())
					: { ...NO_VOTING_PERMISSIONS }
			} catch {
				this.permissions = { ...NO_VOTING_PERMISSIONS }
			}
		},

		/**
		 * Load the declarations on the motion and its agenda item; a failure
		 * leaves the count at all participants.
		 *
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
		 */
		async loadDeclarations() {
			try {
				const store = ensureRelationType('conflict-of-interest')
				const ids = [this.motionId, this.agendaItemId].filter(Boolean)
				const lists = await Promise.all(
					ids.map((id) =>
						store.fetchCollection('conflict-of-interest', {
							agendaItem: id,
							_limit: 100,
						}),
					),
				)
				this.declarations = lists.flat().filter(Boolean)
			} catch {
				this.declarations = []
			}
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 */
		async fetchCurrentRound() {
			this.loading = true
			try {
				// FILTER DIALECT — do not "restore" the `relations.motion` key
				// this replaced. It scoped NOTHING: it is not `@self`, not
				// `_`-prefixed and not a reserved context param, and its value
				// was empty besides (the tab never received an objectId), so
				// `buildQueryString` dropped it and the request went out as a
				// bare `GET …/objects/decidiq/voting-round`. The collection came
				// back UNSCOPED — every voting round on the instance, on a
				// healthy HTTP 200 — and the heuristic below then displayed, and
				// `castVote` posted to, whichever round happened to be open
				// first. That is another motion's round on any instance holding
				// more than one; it merely looked right for as long as every
				// round in the database was closed except the one the test had
				// just written. See src/utils/objectRelations.js for the filter
				// dialect and the shapes `matching()` has to read.
				//
				// `matching()` is the load-bearing half: `_relations_contains`
				// is a server-side narrowing that a given OpenRegister version
				// may or may not honour, and a filter it does not honour is
				// silently ignored rather than refused.
				const [rounds, participants] = await Promise.all([
					this.objectStore.fetchCollection('voting-round', {
						...relationFilterFor(this.motionId),
						_limit: 100,
					}),
					this.meetingId
						? this.objectStore.fetchCollection('participant', {
								'relations.meeting': this.meetingId,
							})
						: Promise.resolve(null),
				])
				const roundList = matching(rounds, this.motionId)
				// Show most recent open round, then most recent closed.
				const open = roundList.find((r) => r.openedAt && !r.closedAt)
				const recent = roundList
					.slice()
					.sort(
						(a, b) =>
							new Date(b.openedAt || 0) - new Date(a.openedAt || 0),
					)[0]
				this.currentRound = open || recent || null
				this.participantCount = participants?.length ?? 0
				await this.loadDeclarations()
			} catch {
				this.currentRound = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param value
		 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1
		 */
		async castVote(value) {
			this.castVoteError = null
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/cast`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							participantId: OC.currentUser,
							value,
							isProxy: false,
							delegatorId: null,
						}),
					},
				)
				if (resp.ok) {
					this.voteCast = true
					await this.fetchCurrentRound()
				} else {
					const data = await resp.json()
					this.castVoteError =
						data.message || this.t('decidiq', 'Failed to cast vote')
				}
			} catch {
				this.castVoteError = this.t('decidiq', 'Failed to cast vote')
			}
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.3 */
		async openRound() {
			this.openingRound = true
			this.openRoundError = null
			try {
				const resp = await fetch(
					OC.generateUrl('/apps/decidiq/api/voting-rounds'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							...votingRoundBody({
								subjectId: this.motionId,
								subjectType: this.subjectType,
								meetingId: this.meetingId,
							}),
							votingMethod: this.newRound.votingMethod,
							isSecret: this.newRound.isSecret,
							closedAt: this.newRound.closedAt || null,
							...chosenRules(this.newRound),
							revoteOfRound: this.revoteOfRoundId || null,
							options: this.rankedOptions(),
						}),
					},
				)
				if (resp.ok) {
					this.showOpenRoundDialog = false
					this.revoteOfRoundId = null
					this.voteCast = false
					await this.fetchCurrentRound()
				} else {
					const data = await resp.json()
					this.openRoundError =
						data.message
						|| this.t('decidiq', 'Failed to open voting round')
				}
			} catch {
				this.openRoundError = this.t(
					'decidiq',
					'Failed to open voting round',
				)
			} finally {
				this.openingRound = false
			}
		},

		/**
		 * The options of a ranked round as the server stores them: a key made
		 * from each label (unique within the round) and the label. Empty for
		 * every other method, which takes no options.
		 *
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
		 * @return {Array<{key: string, label: string}>} The options
		 */
		rankedOptions() {
			if (this.newRound.votingMethod !== 'ranked-choice') {
				return []
			}
			const used = new Set()
			return this.newRound.options
				.map((option) => String(option.label || '').trim())
				.filter((label) => label !== '')
				.map((label, index) => {
					const base =
						label
							.toLowerCase()
							.normalize('NFKD')
							.replace(/[^a-z0-9]+/g, '-')
							.replace(/^-+|-+$/g, '') || `option-${index + 1}`
					let key = base
					let suffix = 2
					while (used.has(key)) {
						key = `${base}-${suffix}`
						suffix++
					}
					used.add(key)
					return { key, label }
				})
		},

		/**
		 * Cast a ranked ballot.
		 *
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
		 * @param {Array<string>} ranking The option keys, first preference first
		 */
		async castRanking(ranking) {
			this.castVoteError = null
			this.castingRanking = true
			try {
				const resp = await fetch(
					generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/cast`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ ranking, isProxy: false }),
					},
				)
				if (resp.ok) {
					this.voteCast = true
					await this.fetchCurrentRound()
				} else {
					const data = await resp.json()
					this.castVoteError =
						data.message || this.t('decidiq', 'Failed to cast vote')
				}
			} catch {
				this.castVoteError = this.t('decidiq', 'Failed to cast vote')
			} finally {
				this.castingRanking = false
			}
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.4 */
		async closeRound() {
			this.confirmCloseRound = false
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/close`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				if (resp.ok) {
					await this.fetchCurrentRound()
				}
			} catch {
				// ignore
			}
		},

		/**
		 * Chair's casting vote resolving a tie under tieBreakRule chair-decides:
		 * re-runs close with the explicit chairCasting value (chair-only, backend-guarded).
		 *
		 * @spec openspec/specs/voting-system/spec.md
		 * @param {string} value 'for' or 'against'
		 */
		async castChairVote(value) {
			this.chairCastingError = null
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/close`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ chairCasting: value }),
					},
				)
				if (resp.ok) {
					await this.fetchCurrentRound()
				} else {
					const data = await resp.json()
					this.chairCastingError =
						data.message || this.t('decidiq', 'Casting vote failed')
				}
			} catch {
				this.chairCastingError = this.t('decidiq', 'Casting vote failed')
			}
		},

		/**
		 * Start the single permitted revote of a tied round: prefill the open
		 * dialog with the tied round's rules and link the new round via revoteOfRound.
		 *
		 * @spec openspec/specs/voting-system/spec.md
		 */
		startRevote() {
			const rules = this.activeRules
			this.newRound = {
				votingMethod:
					this.currentRound?.votingMethod || 'for-against-abstain',

				isSecret: this.currentRound?.isSecret === true,
				closedAt: '',
				voteThreshold: rules.voteThreshold,
				abstentionHandling: rules.abstentionHandling,
				tieBreakRule: rules.tieBreakRule,
				// The server offers a ranked revote the tied options only.
				options: (this.currentRound?.options || []).map((option) => ({
					label: option.label,
				})),
			}
			this.revoteOfRoundId = this.roundId
			this.openRoundError = null
			this.showOpenRoundDialog = true
		},

		/** @spec openspec/specs/voting-system/spec.md */
		cancelRevote() {
			this.showOpenRoundDialog = false
			this.revoteOfRoundId = null
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.6 */
		async saveShowOfHands() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/tally`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							votesFor: this.showOfHands.for,
							votesAgainst: this.showOfHands.against,
							votesAbstain: this.showOfHands.abstain,
						}),
					},
				)
				if (resp.ok) {
					await this.fetchCurrentRound()
				}
			} catch {
				// ignore
			}
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7.1 */
		async grantProxy() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/proxy`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							fromParticipantId: OC.currentUser,
							toParticipantId: this.proxyToId,
						}),
					},
				)
				if (resp.ok) {
					this.activeProxy = this.proxyToId
					this.showProxyDialog = false
				}
			} catch {
				// ignore
			}
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7.2 */
		async revokeProxy() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/proxy`,
					),
					{
						method: 'DELETE',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ fromParticipantId: OC.currentUser }),
					},
				)
				if (resp.ok) {
					this.activeProxy = null
				}
			} catch {
				// ignore
			}
		},

		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.5 */
		async publishToOri() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/voting-rounds/${this.roundId}/publish`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				if (resp.ok) {
					const data = await resp.json()
					this.oriStatus = data.status
				}
			} catch {
				// ignore
			}
		},
	},
}
</script>

<style scoped>
.decidiq-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidiq-vote-buttons {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	align-items: center;
	margin: var(--default-grid-baseline) 0;
}

.decidiq-vote-btn {
	min-width: 100px;
}

.decidiq-vote-confirmed {
	color: var(--color-success);
	font-weight: bold;
}

.decidiq-tally {
	background: var(--color-background-dark);
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline) 0;
}

.decidiq-result {
	background: var(--color-background-dark);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline) 0;
}

.decidiq-proxy-notice {
	background: var(--color-primary-element-light);
	padding: calc(var(--default-grid-baseline) / 2) var(--default-grid-baseline);
	border-radius: var(--border-radius);
	color: var(--color-primary-text);
	width: 100%;
}

.decidiq-proxy {
	margin: var(--default-grid-baseline) 0;
}

.decidiq-dialog {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;
}

.decidiq-dialog label {
	display: block;
	margin: var(--default-grid-baseline) 0 calc(var(--default-grid-baseline) / 2);
}

.decidiq-dialog select,
.decidiq-dialog input[type='datetime-local'],
.decidiq-dialog input[type='text'],
.decidiq-dialog input[type='number'] {
	width: 100%;
	max-width: 300px;
}

.decidiq-dialog-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

.decidiq-error {
	color: var(--color-error);
	margin: var(--default-grid-baseline) 0 0;
}

.decidiq-ori-status {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.decidiq-show-of-hands {
	margin: var(--default-grid-baseline) 0;
}

.decidiq-show-of-hands label {
	display: inline-block;
	width: 100px;
}

.decidiq-show-of-hands input {
	width: 80px;
	margin-bottom: var(--default-grid-baseline);
}

.decidiq-rules {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: calc(var(--default-grid-baseline) / 2) 0 0;
}

.decidiq-chair-casting,
.decidiq-revote {
	border-top: 1px solid var(--color-border);
	margin-top: var(--default-grid-baseline);
	padding-top: var(--default-grid-baseline);
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	align-items: center;
}

.decidiq-revote-notice {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
