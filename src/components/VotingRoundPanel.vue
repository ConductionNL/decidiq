<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Voting Round')">
		<!-- Open voting round dialog trigger (chair/secretary, motion in debating) -->
		<div v-if="canOpenRound" class="decidesk-actions">
			<NcButton
				type="primary"
				@click="showOpenDialog = true">
				{{ t('decidesk', 'Open Voting Round') }}
			</NcButton>
		</div>

		<!-- Open voting round form -->
		<div v-if="showOpenDialog" class="decidesk-voting-form">
			<h4>{{ t('decidesk', 'Open Voting Round') }}</h4>
			<label :for="'voting-method-' + motionId">{{ t('decidesk', 'Voting Method') }}</label>
			<select :id="'voting-method-' + motionId" v-model="openForm.votingMethod">
				<option value="for-against-abstain">{{ t('decidesk', 'For / Against / Abstain') }}</option>
				<option value="show-of-hands">{{ t('decidesk', 'Show of Hands') }}</option>
				<option value="weighted">{{ t('decidesk', 'Weighted') }}</option>
				<option value="ranked-choice">{{ t('decidesk', 'Ranked Choice') }}</option>
			</select>
			<label>
				<input v-model="openForm.isSecret" type="checkbox" />
				{{ t('decidesk', 'Secret Ballot') }}
			</label>
			<label :for="'closed-at-' + motionId">{{ t('decidesk', 'Close At (optional)') }}</label>
			<input
				:id="'closed-at-' + motionId"
				v-model="openForm.closedAt"
				type="datetime-local" />
			<p v-if="openError" class="decidesk-error" role="alert">
				{{ openError }}
			</p>
			<div class="decidesk-actions">
				<NcButton type="primary" :disabled="opening" @click="openVotingRound">
					{{ t('decidesk', 'Open') }}
				</NcButton>
				<NcButton type="tertiary" @click="showOpenDialog = false">
					{{ t('decidesk', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<!-- No active round -->
		<p v-if="!currentRound && !showOpenDialog" class="decidesk-empty">
			{{ t('decidesk', 'No voting round for this item.') }}
		</p>

		<!-- Active or most-recent round -->
		<div v-if="currentRound" class="decidesk-round">
			<!-- Live tally (chair/secretary only) -->
			<div v-if="isChairOrSecretary && isRoundOpen" class="decidesk-tally-live">
				{{ t('decidesk', 'Cast') }}: {{ totalVotesCast }} /
				{{ t('decidesk', 'For') }}: {{ currentRound.votesFor }},
				{{ t('decidesk', 'Against') }}: {{ currentRound.votesAgainst }},
				{{ t('decidesk', 'Abstain') }}: {{ currentRound.votesAbstain }}
			</div>
			<!-- Members see total only -->
			<div v-else-if="isRoundOpen" class="decidesk-tally-partial">
				{{ t('decidesk', 'Votes cast') }}: {{ totalVotesCast }}
			</div>

			<!-- Show-of-hands data entry -->
			<div v-if="isShowOfHands && isRoundOpen && isChairOrSecretary" class="decidesk-show-of-hands">
				<h5>{{ t('decidesk', 'Show of Hands') }}</h5>
				<label :for="'soh-for-' + motionId">{{ t('decidesk', 'For') }}</label>
				<input :id="'soh-for-' + motionId" v-model.number="sohForm.for" type="number" min="0" :aria-label="t('decidesk', 'Votes for')" />
				<label :for="'soh-against-' + motionId">{{ t('decidesk', 'Against') }}</label>
				<input :id="'soh-against-' + motionId" v-model.number="sohForm.against" type="number" min="0" :aria-label="t('decidesk', 'Votes against')" />
				<label :for="'soh-abstain-' + motionId">{{ t('decidesk', 'Abstain') }}</label>
				<input :id="'soh-abstain-' + motionId" v-model.number="sohForm.abstain" type="number" min="0" :aria-label="t('decidesk', 'Votes abstain')" />
				<NcButton type="secondary" @click="saveShowOfHandsResult">
					{{ t('decidesk', 'Save Result') }}
				</NcButton>
			</div>

			<!-- Vote buttons for standard rounds -->
			<div v-if="isRoundOpen && !isShowOfHands && canVote" class="decidesk-vote-buttons" role="group" :aria-label="t('decidesk', 'Cast your vote')">
				<NcButton
					:class="{ 'decidesk-voted': myVote === 'for' }"
					type="primary"
					:aria-pressed="myVote === 'for'"
					:disabled="voting"
					@click="castVote('for')">
					{{ t('decidesk', 'For') }}
				</NcButton>
				<NcButton
					:class="{ 'decidesk-voted': myVote === 'against' }"
					type="error"
					:aria-pressed="myVote === 'against'"
					:disabled="voting"
					@click="castVote('against')">
					{{ t('decidesk', 'Against') }}
				</NcButton>
				<NcButton
					:class="{ 'decidesk-voted': myVote === 'abstain' }"
					type="secondary"
					:aria-pressed="myVote === 'abstain'"
					:disabled="voting"
					@click="castVote('abstain')">
					{{ t('decidesk', 'Abstain') }}
				</NcButton>
			</div>
			<p v-if="myVote && isRoundOpen" class="decidesk-vote-confirmation" role="status">
				{{ t('decidesk', 'Your vote has been recorded') }}: <strong>{{ myVote }}</strong>
			</p>

			<!-- Proxy section: grant/revoke before round opens -->
			<div v-if="!isRoundOpen && canVote" class="decidesk-proxy">
				<NcButton type="secondary" @click="showProxyDialog = true">
					{{ t('decidesk', 'Grant Proxy') }}
				</NcButton>
				<NcButton v-if="hasProxy" type="tertiary" @click="revokeProxy">
					{{ t('decidesk', 'Revoke Proxy') }}
				</NcButton>
			</div>
			<p v-if="receivedProxy" class="decidesk-proxy-notice">
				{{ t('decidesk', 'You are voting on behalf of') }}: <strong>{{ receivedProxy }}</strong>
			</p>

			<!-- Close round button (chair/secretary) -->
			<div v-if="isRoundOpen && isChairOrSecretary" class="decidesk-actions">
				<NcButton
					type="error"
					:disabled="closing"
					@click="confirmClose">
					{{ t('decidesk', 'Close Voting Round') }}
				</NcButton>
			</div>

			<!-- Result display (closed round) -->
			<div v-if="!isRoundOpen && currentRound.result" class="decidesk-result">
				<CnStatusBadge :status="currentRound.result" />
				<div class="decidesk-tally-final">
					{{ t('decidesk', 'For') }}: {{ currentRound.votesFor }} |
					{{ t('decidesk', 'Against') }}: {{ currentRound.votesAgainst }} |
					{{ t('decidesk', 'Abstain') }}: {{ currentRound.votesAbstain }}
				</div>
				<NcButton
					v-if="isChairOrSecretary"
					type="secondary"
					@click="publishToOri">
					{{ t('decidesk', 'Publish to ORI') }}
				</NcButton>
				<span v-if="publicationStatus === 'published'" class="decidesk-published">
					{{ t('decidesk', 'Published') }}
				</span>
				<span v-else-if="publicationStatus === 'pending'" class="decidesk-pending">
					{{ t('decidesk', 'Publication pending') }}
				</span>
			</div>
		</div>
	</CnDetailCard>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'VotingRoundPanel',
	components: { NcButton, CnDetailCard, CnStatusBadge },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		motionSchema: { type: String, default: 'motion' },
	},
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},
	data() {
		return {
			currentRound: null,
			myVote: null,
			hasProxy: false,
			receivedProxy: null,
			publicationStatus: null,
			showOpenDialog: false,
			showProxyDialog: false,
			openError: null,
			opening: false,
			voting: false,
			closing: false,
			pollInterval: null,
			openForm: {
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: null,
			},
			sohForm: { for: 0, against: 0, abstain: 0 },
			// Simplified role detection — real implementation reads from settings store.
			isChairOrSecretary: false,
			canVote: true,
			participantId: null,
		}
	},
	computed: {
		isRoundOpen() {
			return this.currentRound !== null
				&& this.currentRound.openedAt !== null
				&& this.currentRound.closedAt === null
		},
		isShowOfHands() {
			return (this.currentRound?.votingMethod ?? '') === 'show-of-hands'
		},
		canOpenRound() {
			return this.motionLifecycle === 'debating' && !this.currentRound
		},
		totalVotesCast() {
			if (!this.currentRound) return 0
			return (this.currentRound.votesFor ?? 0)
				+ (this.currentRound.votesAgainst ?? 0)
				+ (this.currentRound.votesAbstain ?? 0)
		},
	},
	mounted() {
		this.loadCurrentRound()
	},
	beforeDestroy() {
		if (this.pollInterval) clearInterval(this.pollInterval)
	},
	watch: {
		motionId() { this.loadCurrentRound() },
		isRoundOpen(val) {
			if (val) {
				this.startPolling()
			} else {
				this.stopPolling()
			}
		},
	},
	methods: {
		async loadCurrentRound() {
			try {
				const rounds = await this.objectStore.fetchObjects('voting-round')
				// Find open or most recent round linked to this motion.
				const linked = (rounds ?? []).filter(r => {
					const rels = r.relations?.motion ?? r.relations?.amendment ?? []
					return rels.some(rel => (rel.id ?? rel.uuid ?? rel) === this.motionId)
				})
				const open = linked.find(r => r.openedAt && !r.closedAt)
				this.currentRound = open ?? (linked.sort((a, b) => new Date(b.openedAt ?? 0) - new Date(a.openedAt ?? 0))[0] ?? null)

				if (this.currentRound?.result) {
					await this.checkPublicationStatus()
				}
			} catch { /* ignore */ }
		},
		startPolling() {
			if (this.pollInterval) return
			this.pollInterval = setInterval(() => this.loadCurrentRound(), 5000)
		},
		stopPolling() {
			if (this.pollInterval) {
				clearInterval(this.pollInterval)
				this.pollInterval = null
			}
		},
		async openVotingRound() {
			this.opening = true
			this.openError = null
			try {
				const response = await fetch('/index.php/apps/decidesk/api/voting-rounds', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
					body: JSON.stringify({
						motionId: this.motionId,
						votingMethod: this.openForm.votingMethod,
						isSecret: this.openForm.isSecret,
						closedAt: this.openForm.closedAt || null,
					}),
				})
				const data = await response.json()
				if (!response.ok) {
					this.openError = data.message ?? this.t('decidesk', 'Failed to open voting round')
					return
				}

				this.showOpenDialog = false
				await this.loadCurrentRound()
			} finally {
				this.opening = false
			}
		},
		async castVote(value) {
			this.voting = true
			try {
				const roundId = this.currentRound?.id ?? this.currentRound?.uuid
				await fetch(`/index.php/apps/decidesk/api/voting-rounds/${roundId}/cast`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
					body: JSON.stringify({
						participantId: this.participantId ?? '',
						value,
						isProxy: false,
						delegatorId: null,
					}),
				})
				this.myVote = value
				await this.loadCurrentRound()
			} finally {
				this.voting = false
			}
		},
		async saveShowOfHandsResult() {
			const roundId = this.currentRound?.id ?? this.currentRound?.uuid
			// Update voting round totals directly.
			await this.objectStore.saveObject('voting-round', {
				...(this.currentRound ?? {}),
				votesFor: this.sohForm.for,
				votesAgainst: this.sohForm.against,
				votesAbstain: this.sohForm.abstain,
			})
			await this.loadCurrentRound()
		},
		async confirmClose() {
			if (!confirm(this.t('decidesk', 'Close the voting round now? Members who have not voted yet will not be counted.'))) return
			this.closing = true
			try {
				const roundId = this.currentRound?.id ?? this.currentRound?.uuid
				await fetch(`/index.php/apps/decidesk/api/voting-rounds/${roundId}/close`, {
					method: 'POST',
					headers: { 'Accept': 'application/json' },
				})
				await this.loadCurrentRound()
			} finally {
				this.closing = false
			}
		},
		async publishToOri() {
			const roundId = this.currentRound?.id ?? this.currentRound?.uuid
			const response = await fetch(`/index.php/apps/decidesk/api/voting-rounds/${roundId}/publish`, {
				method: 'POST',
				headers: { 'Accept': 'application/json' },
			})
			const data = await response.json()
			this.publicationStatus = data.status ?? null
		},
		async revokeProxy() {
			const roundId = this.currentRound?.id ?? this.currentRound?.uuid
			await fetch(`/index.php/apps/decidesk/api/voting-rounds/${roundId}/proxy`, {
				method: 'DELETE',
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
				body: JSON.stringify({ fromParticipantId: this.participantId ?? '' }),
			})
			this.hasProxy = false
		},
		async checkPublicationStatus() {
			const roundId = this.currentRound?.id ?? this.currentRound?.uuid
			if (!roundId) return
			try {
				const response = await fetch(`/index.php/apps/decidesk/api/voting-rounds/${roundId}/publish`, {
					method: 'POST',
					headers: { 'Accept': 'application/json' },
				})
				const data = await response.json()
				this.publicationStatus = data.status ?? null
			} catch { /* ignore */ }
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

.decidesk-vote-buttons {
	display: flex;
	gap: var(--default-grid-baseline);
	margin: calc(var(--default-grid-baseline) * 2) 0;
}

.decidesk-voted {
	outline: 3px solid var(--color-primary);
}

.decidesk-vote-confirmation {
	color: var(--color-success);
	font-weight: bold;
	margin-top: var(--default-grid-baseline);
}

.decidesk-tally-live,
.decidesk-tally-partial,
.decidesk-tally-final {
	margin: var(--default-grid-baseline) 0;
	font-size: 0.95em;
	color: var(--color-text-light);
}

.decidesk-tally-live {
	font-weight: bold;
	color: var(--color-text);
}

.decidesk-result {
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.decidesk-published {
	color: var(--color-success);
	font-size: 0.9em;
	margin-left: var(--default-grid-baseline);
}

.decidesk-pending {
	color: var(--color-warning);
	font-size: 0.9em;
	margin-left: var(--default-grid-baseline);
}

.decidesk-proxy-notice {
	color: var(--color-primary);
	font-size: 0.9em;
	margin: var(--default-grid-baseline) 0;
}

.decidesk-proxy {
	margin: var(--default-grid-baseline) 0;
}

.decidesk-voting-form {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-error {
	color: var(--color-error);
	font-weight: bold;
}

.decidesk-show-of-hands {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline) 0;
}

.decidesk-round {
	margin-top: var(--default-grid-baseline);
}
</style>
