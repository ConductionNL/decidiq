<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VotingRoundPanel component — embedded in MotionDetail and AmendmentDetail.
 Shows the current open voting round, vote casting, live tally, proxy controls, and results.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Voting Round')">
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
				v-if="motionLifecycle === 'debating'"
				type="primary"
				:disabled="!meetingId"
				:title="!meetingId ? t('decidesk', 'Meeting not linked — voting round cannot be opened') : undefined"
				@click="showOpenRoundDialog = true">
				{{ t('decidesk', 'Open Voting Round') }}
			</NcButton>

			<!-- Open round dialog -->
			<div v-if="showOpenRoundDialog"
				class="decidesk-dialog"
				role="dialog"
				:aria-label="t('decidesk', 'Open Voting Round')">
				<h3>{{ t('decidesk', 'Open Voting Round') }}</h3>
				<label for="votingMethod">{{ t('decidesk', 'Voting Method') }}</label>
				<select id="votingMethod" v-model="newRound.votingMethod">
					<option value="for-against-abstain">
						{{ t('decidesk', 'For / Against / Abstain') }}
					</option>
					<option value="show-of-hands">
						{{ t('decidesk', 'Show of Hands') }}
					</option>
					<option value="weighted">
						{{ t('decidesk', 'Weighted') }}
					</option>
				</select>
				<label>
					<input v-model="newRound.isSecret" type="checkbox">
					{{ t('decidesk', 'Secret Ballot') }}
				</label>
				<label for="closedAt">{{ t('decidesk', 'Close At (optional)') }}</label>
				<input id="closedAt" v-model="newRound.closedAt" type="datetime-local">
				<p v-if="openRoundError" class="decidesk-error" role="alert">
					{{ openRoundError }}
				</p>
				<div class="decidesk-dialog-actions">
					<NcButton type="primary" :disabled="openingRound" @click="openRound">
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
			<div v-if="isRoundOpen && currentRound.votingMethod === 'show-of-hands'" class="decidesk-show-of-hands">
				<h4>{{ t('decidesk', 'Save Result') }}</h4>
				<label for="showFor">{{ t('decidesk', 'For') }}</label>
				<input id="showFor"
					v-model.number="showOfHands.for"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes for')">
				<label for="showAgainst">{{ t('decidesk', 'Against') }}</label>
				<input id="showAgainst"
					v-model.number="showOfHands.against"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes against')">
				<label for="showAbstain">{{ t('decidesk', 'Abstain') }}</label>
				<input id="showAbstain"
					v-model.number="showOfHands.abstain"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes abstain')">
				<NcButton type="primary" @click="saveShowOfHands">
					{{ t('decidesk', 'Save Result') }}
				</NcButton>
			</div>

			<!-- Vote casting buttons -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 -->
			<div v-if="isRoundOpen && currentRound.votingMethod !== 'show-of-hands' && !voteCast" class="decidesk-vote-buttons">
				<!-- Proxy notice -->
				<p v-if="activeProxy" class="decidesk-proxy-notice">
					{{ t('decidesk', 'You are voting on behalf of') }}: {{ activeProxy }}
				</p>
				<NcButton
					type="primary"
					class="decidesk-vote-btn"
					:aria-label="t('decidesk', 'Cast your vote')"
					@click="castVote('for')">
					{{ t('decidesk', 'For') }}
				</NcButton>
				<NcButton
					type="error"
					class="decidesk-vote-btn"
					:aria-label="t('decidesk', 'Cast your vote')"
					@click="castVote('against')">
					{{ t('decidesk', 'Against') }}
				</NcButton>
				<NcButton
					type="secondary"
					class="decidesk-vote-btn"
					:aria-label="t('decidesk', 'Cast your vote')"
					@click="castVote('abstain')">
					{{ t('decidesk', 'Abstain') }}
				</NcButton>
				<p v-if="castVoteError" class="decidesk-error" role="alert">
					{{ castVoteError }}
				</p>
			</div>

			<!-- Vote confirmation message -->
			<p v-if="voteCast" class="decidesk-vote-confirmed" role="status">
				{{ t('decidesk', 'Your vote has been recorded') }}
			</p>

			<!-- Live tally (chair/secretary see full tally; members see only total count) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.2 -->
			<div v-if="isRoundOpen" class="decidesk-tally">
				<p>{{ t('decidesk', 'Votes cast') }}: {{ tallyTotal }} / {{ participantCount }}</p>
				<template v-if="isChairOrSecretary">
					<p>
						{{ t('decidesk', 'Votes for') }}: {{ currentRound.votesFor || 0 }} &mdash;
						{{ t('decidesk', 'Votes against') }}: {{ currentRound.votesAgainst || 0 }} &mdash;
						{{ t('decidesk', 'Votes abstain') }}: {{ currentRound.votesAbstain || 0 }}
					</p>
				</template>
			</div>

			<!-- Proxy management — proxy grant/revoke is enforced by the backend -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7 -->
			<div class="decidesk-proxy">
				<NcButton v-if="!activeProxy" type="secondary" @click="showProxyDialog = true">
					{{ t('decidesk', 'Grant Proxy') }}
				</NcButton>
				<NcButton v-if="activeProxy" type="error" @click="revokeProxy">
					{{ t('decidesk', 'Revoke Proxy') }}
				</NcButton>
				<div v-if="showProxyDialog"
					class="decidesk-dialog"
					role="dialog"
					:aria-label="t('decidesk', 'Grant Proxy')">
					<h4>{{ t('decidesk', 'Grant Proxy') }}</h4>
					<input v-model="proxyToId" type="text" :placeholder="t('decidesk', 'Participant UUID')">
					<div class="decidesk-dialog-actions">
						<NcButton type="primary" @click="grantProxy">
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
				type="error"
				@click="confirmCloseRound = true">
				{{ t('decidesk', 'Close Voting Round') }}
			</NcButton>
			<div v-if="confirmCloseRound" class="decidesk-dialog" role="dialog">
				<p>{{ t('decidesk', 'Close the voting round now? Members who have not voted yet will not be counted.') }}</p>
				<div class="decidesk-dialog-actions">
					<NcButton type="error" @click="closeRound">
						{{ t('decidesk', 'Close Voting Round') }}
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
					<strong>{{ t('decidesk', 'Result') }}:</strong>
					<CnStatusBadge :status="currentRound.result" />
				</p>
				<p>
					{{ t('decidesk', 'Votes for') }}: {{ currentRound.votesFor || 0 }} &mdash;
					{{ t('decidesk', 'Votes against') }}: {{ currentRound.votesAgainst || 0 }} &mdash;
					{{ t('decidesk', 'Votes abstain') }}: {{ currentRound.votesAbstain || 0 }}
				</p>
				<NcButton
					v-if="isChairOrSecretary"
					type="secondary"
					@click="publishToOri">
					{{ t('decidesk', 'Publish to ORI') }}
				</NcButton>
				<p v-if="oriStatus" class="decidesk-ori-status">
					{{ oriStatusLabel }}
				</p>
			</div>
		</template>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useObjectStore, useSettingsStore } from '../store/store.js'

export default {
	name: 'VotingRoundPanel',
	components: { CnDetailCard, CnStatusBadge, NcButton },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		meetingId: { type: String, default: '' },
	},
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
			},
			pollInterval: null,
			participantCount: 0,
		}
	},
	computed: {
		roundId() {
			if (!this.currentRound) return null
			return this.currentRound.id || this.currentRound.uuid || null
		},
		isRoundOpen() {
			if (!this.currentRound) return false
			if (!this.currentRound.openedAt) return false
			const closedAt = this.currentRound.closedAt
			if (closedAt && new Date(closedAt) <= new Date()) return false
			return true
		},
		tallyTotal() {
			if (!this.currentRound) return 0
			return (this.currentRound.votesFor || 0) + (this.currentRound.votesAgainst || 0) + (this.currentRound.votesAbstain || 0)
		},
		isChairOrSecretary() {
			return this.settingsStore.isAdmin === true
		},
		oriStatusLabel() {
			const labels = {
				published: this.t('decidesk', 'Published to ORI'),
				pending: this.t('decidesk', 'Publication pending'),
				not_configured: this.t('decidesk', 'ORI not configured'),
			}
			return labels[this.oriStatus] || this.oriStatus
		},
	},
	async mounted() {
		await this.fetchCurrentRound()
		// Poll every 5 seconds when round is open.
		this.pollInterval = setInterval(async () => {
			if (this.isRoundOpen) {
				await this.fetchCurrentRound()
			}
		}, 5000)
	},
	beforeDestroy() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
		}
	},
	methods: {
		async fetchCurrentRound() {
			this.loading = true
			try {
				const [roundResult, participantResult] = await Promise.all([
					this.objectStore.fetchObjects('voting-round', {
						'relations.motion': this.motionId,
					}),
					this.meetingId
						? this.objectStore.fetchObjects('participant', { 'relations.meeting': this.meetingId })
						: Promise.resolve(null),
				])
				const rounds = roundResult?.results || []
				// Show most recent open round, then most recent closed.
				const open = rounds.find(r => r.openedAt && !r.closedAt)
				const recent = rounds.sort((a, b) => new Date(b.openedAt || 0) - new Date(a.openedAt || 0))[0]
				this.currentRound = open || recent || null
				this.participantCount = participantResult?.results?.length ?? 0
			} catch (e) {
				this.currentRound = null
			} finally {
				this.loading = false
			}
		},
		async castVote(value) {
			this.castVoteError = null
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/voting-rounds/${this.roundId}/cast`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
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
					this.castVoteError = data.message || this.t('decidesk', 'Failed to cast vote')
				}
			} catch (e) {
				this.castVoteError = this.t('decidesk', 'Failed to cast vote')
			}
		},
		async openRound() {
			this.openingRound = true
			this.openRoundError = null
			try {
				const resp = await fetch(
					OC.generateUrl('/apps/decidesk/api/voting-rounds'),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({
							motionId: this.motionId,
							meetingId: this.meetingId,
							votingMethod: this.newRound.votingMethod,
							isSecret: this.newRound.isSecret,
							closedAt: this.newRound.closedAt || null,
						}),
					},
				)
				if (resp.ok) {
					this.showOpenRoundDialog = false
					await this.fetchCurrentRound()
				} else {
					const data = await resp.json()
					this.openRoundError = data.message || this.t('decidesk', 'Failed to open voting round')
				}
			} catch (e) {
				this.openRoundError = this.t('decidesk', 'Failed to open voting round')
			} finally {
				this.openingRound = false
			}
		},
		async closeRound() {
			this.confirmCloseRound = false
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/voting-rounds/${this.roundId}/close`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					},
				)
				if (resp.ok) {
					await this.fetchCurrentRound()
				}
			} catch (e) {
				// ignore
			}
		},
		async saveShowOfHands() {
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/voting-rounds/${this.roundId}/tally`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
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
		async grantProxy() {
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/voting-rounds/${this.roundId}/proxy`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
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
		async revokeProxy() {
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/voting-rounds/${this.roundId}/proxy`),
					{
						method: 'DELETE',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
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
		async publishToOri() {
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/voting-rounds/${this.roundId}/publish`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
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
</style>
