<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VotingRoundPanel component — embedded in MotionDetail and AmendmentDetail.
 Shows the current open voting round, vote casting, live tally, proxy controls, and results.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Voting round')">
		<!-- Loading state -->
		<p v-if="loading" class="decidesk-empty">
			{{ t('decidesk', 'Loading…') }}
		</p>

		<!-- No round yet — chair can open one -->
		<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.3 -->
		<template v-else-if="!currentRound">
			<p class="decidesk-empty">
				{{ t('decidesk', 'No active voting round.') }}
			</p>
			<NcButton
				v-if="motionLifecycle === 'deliberating'"
				variant="primary"
				:disabled="!meetingId"
				:title="
					!meetingId
						? t(
								'decidesk',
								'No meeting linked — the voting round cannot be opened',
							)
						: undefined
				"
				@click="showOpenRoundDialog = true">
				{{ t('decidesk', 'Open voting round') }}
			</NcButton>

			<!-- Open round dialog -->
			<div
				v-if="showOpenRoundDialog"
				class="decidesk-dialog"
				role="dialog"
				:aria-label="t('decidesk', 'Open voting round')">
				<h3>{{ t('decidesk', 'Open voting round') }}</h3>
				<label for="votingMethod">{{
					t('decidesk', 'Voting method')
				}}</label>
				<select id="votingMethod" v-model="newRound.votingMethod">
					<option value="for-against-abstain">
						{{ t('decidesk', 'For / Against / Abstain') }}
					</option>
					<option value="show-of-hands">
						{{ t('decidesk', 'Show of hands') }}
					</option>
					<option value="weighted">
						{{ t('decidesk', 'Weighted vote') }}
					</option>
				</select>
				<label>
					<input v-model="newRound.isSecret" type="checkbox" />
					{{ t('decidesk', 'Secret ballot') }}
				</label>
				<!-- Configurable voting rules (voting-system spec) -->
				<!-- @spec openspec/specs/voting-system/spec.md -->
				<label for="voteThreshold">{{
					t('decidesk', 'Vote threshold')
				}}</label>
				<select
					id="voteThreshold"
					v-model="newRound.voteThreshold"
					data-testid="vote-threshold-select">
					<option
						v-for="value in voteThresholdOptions"
						:key="value"
						:value="value">
						{{ labels.voteThreshold[value] }}
					</option>
				</select>
				<label for="abstentionHandling">{{
					t('decidesk', 'Abstention handling')
				}}</label>
				<select
					id="abstentionHandling"
					v-model="newRound.abstentionHandling"
					data-testid="abstention-handling-select">
					<option
						v-for="value in abstentionModeOptions"
						:key="value"
						:value="value">
						{{ labels.abstentionHandling[value] }}
					</option>
				</select>
				<label for="tieBreakRule">{{
					t('decidesk', 'Tie-break rule')
				}}</label>
				<select
					id="tieBreakRule"
					v-model="newRound.tieBreakRule"
					data-testid="tie-break-rule-select">
					<option
						v-for="value in tieBreakRuleOptions"
						:key="value"
						:value="value">
						{{ labels.tieBreakRule[value] }}
					</option>
				</select>
				<p v-if="revoteOfRoundId" class="decidesk-revote-notice">
					{{
						t(
							'decidesk',
							'This round is the single permitted revote of the tied round.',
						)
					}}
				</p>
				<label for="closedAt">{{
					t('decidesk', 'Closing time (optional)')
				}}</label>
				<input
					id="closedAt"
					v-model="newRound.closedAt"
					type="datetime-local" />
				<p v-if="openRoundError" class="decidesk-error" role="alert">
					{{ openRoundError }}
				</p>
				<div class="decidesk-dialog-actions">
					<NcButton
						variant="primary"
						:disabled="openingRound"
						@click="openRound">
						{{ t('decidesk', 'Open') }}
					</NcButton>
					<NcButton @click="showOpenRoundDialog = false">
						{{ t('decidesk', 'Cancel') }}
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
				class="decidesk-show-of-hands">
				<h4>{{ t('decidesk', 'Save show-of-hands result') }}</h4>
				<label for="showFor">{{ t('decidesk', 'For') }}</label>
				<input
					id="showFor"
					v-model.number="showOfHands.for"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes for')" />
				<label for="showAgainst">{{ t('decidesk', 'Against') }}</label>
				<input
					id="showAgainst"
					v-model.number="showOfHands.against"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes against')" />
				<label for="showAbstain">{{ t('decidesk', 'Abstain') }}</label>
				<input
					id="showAbstain"
					v-model.number="showOfHands.abstain"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Abstentions')" />
				<NcButton variant="primary" @click="saveShowOfHands">
					{{ t('decidesk', 'Save result') }}
				</NcButton>
			</div>

			<!-- Vote casting buttons -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 -->
			<div
				v-if="
					isRoundOpen
					&& currentRound.votingMethod !== 'show-of-hands'
					&& !voteCast
				"
				class="decidesk-vote-buttons">
				<!-- Proxy notice -->
				<p v-if="activeProxy" class="decidesk-proxy-notice">
					{{
						t('decidesk', 'You are voting on behalf of: {name}', {
							name: activeProxy,
						})
					}}
				</p>
				<NcButton
					variant="primary"
					class="decidesk-vote-btn"
					:aria-label="t('decidesk', 'Vote for')"
					@click="castVote('for')">
					{{ t('decidesk', 'For') }}
				</NcButton>
				<NcButton
					variant="error"
					class="decidesk-vote-btn"
					:aria-label="t('decidesk', 'Vote against')"
					@click="castVote('against')">
					{{ t('decidesk', 'Against') }}
				</NcButton>
				<NcButton
					variant="secondary"
					class="decidesk-vote-btn"
					:aria-label="t('decidesk', 'Abstain')"
					@click="castVote('abstain')">
					{{ t('decidesk', 'Abstain') }}
				</NcButton>
				<p v-if="castVoteError" class="decidesk-error" role="alert">
					{{ castVoteError }}
				</p>
			</div>

			<!-- Vote confirmation message -->
			<p v-if="voteCast" class="decidesk-vote-confirmed" role="status">
				{{ t('decidesk', 'Your vote has been recorded.') }}
			</p>

			<!-- Live tally (chair/secretary see full tally; members see only total count) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.2 -->
			<div v-if="isRoundOpen" class="decidesk-tally">
				<p>
					{{
						t('decidesk', 'Cast: {cast} / {total}', {
							cast: tallyTotal,
							total: participantCount,
						})
					}}
				</p>
				<template v-if="isChairOrSecretary">
					<p>
						{{
							t(
								'decidesk',
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
				<p class="decidesk-rules" data-testid="active-voting-rules">
					{{ activeRulesSummary }}
				</p>
			</div>

			<!-- Proxy management — proxy grant/revoke is enforced by the backend -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7 -->
			<div class="decidesk-proxy">
				<NcButton
					v-if="!activeProxy"
					variant="secondary"
					@click="showProxyDialog = true">
					{{ t('decidesk', 'Grant proxy') }}
				</NcButton>
				<NcButton v-if="activeProxy" variant="error" @click="revokeProxy">
					{{ t('decidesk', 'Revoke proxy') }}
				</NcButton>
				<div
					v-if="showProxyDialog"
					class="decidesk-dialog"
					role="dialog"
					:aria-label="t('decidesk', 'Grant proxy')">
					<h4>{{ t('decidesk', 'Grant proxy to') }}</h4>
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
						:label="t('decidesk', 'Participant UUID')"
						:placeholder="t('decidesk', 'Participant UUID')" />
					<div class="decidesk-dialog-actions">
						<NcButton variant="primary" @click="grantProxy">
							{{ t('decidesk', 'Grant') }}
						</NcButton>
						<NcButton @click="showProxyDialog = false">
							{{ t('decidesk', 'Cancel') }}
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
				{{ t('decidesk', 'Close voting round') }}
			</NcButton>
			<div v-if="confirmCloseRound" class="decidesk-dialog" role="dialog">
				<p>
					{{
						t(
							'decidesk',
							'Close voting round? {notVoted} of {total} members have not voted yet.',
							{
								notVoted: participantCount - tallyTotal,
								total: participantCount,
							},
						)
					}}
				</p>
				<div class="decidesk-dialog-actions">
					<NcButton variant="error" @click="closeRound">
						{{ t('decidesk', 'Close') }}
					</NcButton>
					<NcButton @click="confirmCloseRound = false">
						{{ t('decidesk', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<!-- Result display (closed rounds) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.5 -->
			<div v-if="!isRoundOpen && currentRound.result" class="decidesk-result">
				<p>
					<strong>{{ t('decidesk', 'Result:') }}</strong>
					<CnStatusBadge :status="currentRound.result" />
				</p>
				<p>
					{{
						t(
							'decidesk',
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
				<p class="decidesk-rules" data-testid="result-voting-rules">
					{{ activeRulesSummary }}
				</p>
				<p
					v-if="currentRound.chairCastingVote"
					class="decidesk-rules"
					data-testid="chair-casting-recorded">
					{{
						t(
							'decidesk',
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
						&& isChairOrSecretary
					"
					class="decidesk-chair-casting"
					data-testid="chair-casting-controls">
					<p>
						{{
							t(
								'decidesk',
								'The vote is tied. As chair you must resolve it with a casting vote.',
							)
						}}
					</p>
					<NcButton variant="primary" @click="castChairVote('for')">
						{{ t('decidesk', 'Casting vote: for') }}
					</NcButton>
					<NcButton variant="error" @click="castChairVote('against')">
						{{ t('decidesk', 'Casting vote: against') }}
					</NcButton>
					<p v-if="chairCastingError" class="decidesk-error" role="alert">
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
					class="decidesk-revote"
					data-testid="revote-controls">
					<p>
						{{
							t(
								'decidesk',
								'The vote is tied. The round may be reopened once for a revote.',
							)
						}}
					</p>
					<NcButton variant="primary" @click="startRevote">
						{{ t('decidesk', 'Reopen round (revote)') }}
					</NcButton>
				</div>

				<NcButton
					v-if="isChairOrSecretary"
					variant="secondary"
					@click="publishToOri">
					{{ t('decidesk', 'Publish to ORI') }}
				</NcButton>
				<p v-if="oriStatus" class="decidesk-ori-status">
					{{ oriStatusLabel }}
				</p>
			</div>

			<!-- Revote open dialog (reuses the rule selectors with the tied round's rules prefilled) -->
			<div
				v-if="showOpenRoundDialog && revoteOfRoundId"
				class="decidesk-dialog"
				role="dialog"
				:aria-label="t('decidesk', 'Reopen round (revote)')">
				<h3>{{ t('decidesk', 'Reopen round (revote)') }}</h3>
				<p>
					{{
						t(
							'decidesk',
							'This round is the single permitted revote of the tied round.',
						)
					}}
				</p>
				<p v-if="openRoundError" class="decidesk-error" role="alert">
					{{ openRoundError }}
				</p>
				<div class="decidesk-dialog-actions">
					<NcButton
						variant="primary"
						:disabled="openingRound"
						@click="openRound">
						{{ t('decidesk', 'Open') }}
					</NcButton>
					<NcButton @click="cancelRevote">
						{{ t('decidesk', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</template>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { useObjectStore, useSettingsStore } from '../store/store.js'
import {
	ABSTENTION_MODES,
	TIE_BREAK_RULES,
	VOTE_THRESHOLDS,
	computeBase,
	effectiveRules,
	ruleLabels,
} from '../utils/votingRules.js'

export default {
	name: 'VotingRoundPanel',
	components: { CnDetailCard, CnStatusBadge, NcButton, NcTextField },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		meetingId: { type: String, default: '' },
	},
	/** @spec exclude setup() only wires the shared object + settings store refs; no domain logic */
	setup() {
		const objectStore = useObjectStore()
		const settingsStore = useSettingsStore()
		return { objectStore, settingsStore }
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
				voteThreshold: 'simple-majority',
				abstentionHandling: 'exclude',
				tieBreakRule: 'rejected',
			},
			revoteOfRoundId: null,
			chairCastingError: null,
			pollInterval: null,
			participantCount: 0,
		}
	},
	computed: {
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
		isChairOrSecretary() {
			return this.settingsStore.isAdmin === true
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
		/** Translated labels per rule enum value. @spec openspec/specs/voting-system/spec.md */
		labels() {
			return ruleLabels((text) => this.t('decidesk', text))
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
				'decidesk',
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
				published: this.t('decidesk', 'Published to ORI'),
				pending: this.t('decidesk', 'Publication pending'),
				not_configured: this.t('decidesk', 'ORI not configured'),
			}
			return labels[this.oriStatus] || this.oriStatus
		},
	},
	/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.2 */
	async mounted() {
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
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 */
		async fetchCurrentRound() {
			this.loading = true
			try {
				const [rounds, participants] = await Promise.all([
					this.objectStore.fetchCollection('voting-round', {
						'relations.motion': this.motionId,
					}),
					this.meetingId
						? this.objectStore.fetchCollection('participant', {
								'relations.meeting': this.meetingId,
							})
						: Promise.resolve(null),
				])
				const roundList = rounds || []
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
			} catch (e) {
				this.currentRound = null
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 */
		async castVote(value) {
			this.castVoteError = null
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/voting-rounds/${this.roundId}/cast`,
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
						data.message || this.t('decidesk', 'Failed to cast vote')
				}
			} catch (e) {
				this.castVoteError = this.t('decidesk', 'Failed to cast vote')
			}
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.3 */
		async openRound() {
			this.openingRound = true
			this.openRoundError = null
			try {
				const resp = await fetch(
					OC.generateUrl('/apps/decidesk/api/voting-rounds'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							motionId: this.motionId,
							meetingId: this.meetingId,
							votingMethod: this.newRound.votingMethod,
							isSecret: this.newRound.isSecret,
							closedAt: this.newRound.closedAt || null,
							voteThreshold: this.newRound.voteThreshold,
							abstentionHandling: this.newRound.abstentionHandling,
							tieBreakRule: this.newRound.tieBreakRule,
							revoteOfRound: this.revoteOfRoundId || null,
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
						|| this.t('decidesk', 'Failed to open voting round')
				}
			} catch (e) {
				this.openRoundError = this.t(
					'decidesk',
					'Failed to open voting round',
				)
			} finally {
				this.openingRound = false
			}
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.4 */
		async closeRound() {
			this.confirmCloseRound = false
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/voting-rounds/${this.roundId}/close`,
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
			} catch (e) {
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
						`/apps/decidesk/api/voting-rounds/${this.roundId}/close`,
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
						data.message || this.t('decidesk', 'Casting vote failed')
				}
			} catch (e) {
				this.chairCastingError = this.t('decidesk', 'Casting vote failed')
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
						`/apps/decidesk/api/voting-rounds/${this.roundId}/tally`,
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
			} catch (e) {
				// ignore
			}
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7.1 */
		async grantProxy() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/voting-rounds/${this.roundId}/proxy`,
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
			} catch (e) {
				// ignore
			}
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7.2 */
		async revokeProxy() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/voting-rounds/${this.roundId}/proxy`,
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
			} catch (e) {
				// ignore
			}
		},
		/** @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.5 */
		async publishToOri() {
			try {
				const resp = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/voting-rounds/${this.roundId}/publish`,
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
			} catch (e) {
				// ignore
			}
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-vote-buttons {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	align-items: center;
	margin: var(--default-grid-baseline) 0;
}

.decidesk-vote-btn {
	min-width: 100px;
}

.decidesk-vote-confirmed {
	color: var(--color-success);
	font-weight: bold;
}

.decidesk-tally {
	background: var(--color-background-dark);
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline) 0;
}

.decidesk-result {
	background: var(--color-background-dark);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline) 0;
}

.decidesk-proxy-notice {
	background: var(--color-primary-element-light);
	padding: calc(var(--default-grid-baseline) / 2) var(--default-grid-baseline);
	border-radius: var(--border-radius);
	color: var(--color-primary-text);
	width: 100%;
}

.decidesk-proxy {
	margin: var(--default-grid-baseline) 0;
}

.decidesk-dialog {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;
}

.decidesk-dialog label {
	display: block;
	margin: var(--default-grid-baseline) 0 calc(var(--default-grid-baseline) / 2);
}

.decidesk-dialog select,
.decidesk-dialog input[type='datetime-local'],
.decidesk-dialog input[type='text'],
.decidesk-dialog input[type='number'] {
	width: 100%;
	max-width: 300px;
}

.decidesk-dialog-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

.decidesk-error {
	color: var(--color-error);
	margin: var(--default-grid-baseline) 0 0;
}

.decidesk-ori-status {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.decidesk-show-of-hands {
	margin: var(--default-grid-baseline) 0;
}

.decidesk-show-of-hands label {
	display: inline-block;
	width: 100px;
}

.decidesk-show-of-hands input {
	width: 80px;
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-rules {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: calc(var(--default-grid-baseline) / 2) 0 0;
}

.decidesk-chair-casting,
.decidesk-revote {
	border-top: 1px solid var(--color-border);
	margin-top: var(--default-grid-baseline);
	padding-top: var(--default-grid-baseline);
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	align-items: center;
}

.decidesk-revote-notice {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
