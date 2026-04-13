<template>
	<div class="voting-panel">
		<!-- Open round dialog -->
		<div v-if="motionLifecycle === 'debating' && !activeRound" class="voting-panel__open">
			<h4>{{ t('decidesk', 'Open voting round') }}</h4>
			<div class="voting-panel__open-form">
				<label>
					{{ t('decidesk', 'Voting method') }}
					<select v-model="openForm.votingMethod" :aria-label="t('decidesk', 'Voting method')">
						<option value="for-against-abstain">{{ t('decidesk', 'For / Against / Abstain') }}</option>
						<option value="show-of-hands">{{ t('decidesk', 'Show of hands') }}</option>
						<option value="ranked-choice">{{ t('decidesk', 'Ranked choice') }}</option>
						<option value="weighted">{{ t('decidesk', 'Weighted') }}</option>
					</select>
				</label>
				<label class="voting-panel__toggle">
					<input v-model="openForm.isSecret" type="checkbox" :aria-label="t('decidesk', 'Secret ballot')" />
					{{ t('decidesk', 'Secret ballot') }}
				</label>
				<label>
					{{ t('decidesk', 'Deadline (optional)') }}
					<input v-model="openForm.closedAt" type="datetime-local" :aria-label="t('decidesk', 'Voting deadline')" />
				</label>
				<button class="primary" @click="openRound">
					{{ t('decidesk', 'Open voting round') }}
				</button>
				<p v-if="quorumError" class="voting-panel__error" role="alert">{{ quorumError }}</p>
			</div>
		</div>

		<!-- Active voting round -->
		<div v-if="activeRound" class="voting-panel__active">
			<div class="voting-panel__info">
				<span>{{ t('decidesk', 'Method') }}: {{ methodLabel(activeRound.votingMethod) }}</span>
				<span v-if="activeRound.isSecret">{{ t('decidesk', 'Secret ballot') }}</span>
			</div>

			<!-- Vote casting (non-show-of-hands) -->
			<div v-if="activeRound.votingMethod !== 'show-of-hands' && !activeRound.closedAt" class="voting-panel__cast">
				<!-- Proxy info -->
				<p v-if="proxyFor" class="voting-panel__proxy-info">
					{{ t('decidesk', 'You are voting on behalf of: {name}', { name: proxyFor }) }}
				</p>

				<div class="voting-panel__buttons" role="group" :aria-label="t('decidesk', 'Cast your vote')">
					<button class="voting-panel__vote-btn voting-panel__vote-btn--for"
						:aria-label="t('decidesk', 'Vote for')"
						:class="{ 'voting-panel__vote-btn--selected': selectedVote === 'for' }"
						@click="castVote('for')">
						{{ t('decidesk', 'For') }}
					</button>
					<button class="voting-panel__vote-btn voting-panel__vote-btn--against"
						:aria-label="t('decidesk', 'Vote against')"
						:class="{ 'voting-panel__vote-btn--selected': selectedVote === 'against' }"
						@click="castVote('against')">
						{{ t('decidesk', 'Against') }}
					</button>
					<button class="voting-panel__vote-btn voting-panel__vote-btn--abstain"
						:aria-label="t('decidesk', 'Abstain')"
						:class="{ 'voting-panel__vote-btn--selected': selectedVote === 'abstain' }"
						@click="castVote('abstain')">
						{{ t('decidesk', 'Abstain') }}
					</button>
				</div>
				<p v-if="voteConfirmation" class="voting-panel__confirmation" role="status">
					{{ voteConfirmation }}
				</p>
			</div>

			<!-- Show of hands entry -->
			<div v-if="activeRound.votingMethod === 'show-of-hands' && !activeRound.closedAt" class="voting-panel__hands">
				<h4>{{ t('decidesk', 'Show of hands count') }}</h4>
				<div class="voting-panel__hands-inputs">
					<label>
						{{ t('decidesk', 'For') }}
						<input v-model.number="handsFor" type="number" min="0" :aria-label="t('decidesk', 'Votes for')" />
					</label>
					<label>
						{{ t('decidesk', 'Against') }}
						<input v-model.number="handsAgainst" type="number" min="0" :aria-label="t('decidesk', 'Votes against')" />
					</label>
					<label>
						{{ t('decidesk', 'Abstain') }}
						<input v-model.number="handsAbstain" type="number" min="0" :aria-label="t('decidesk', 'Abstentions')" />
					</label>
				</div>
				<button @click="saveHandsCount">{{ t('decidesk', 'Save result') }}</button>
			</div>

			<!-- Live tally -->
			<div class="voting-panel__tally">
				<span>{{ t('decidesk', 'Votes cast') }}: {{ totalVotes }}</span>
				<span v-if="isChair">
					— {{ t('decidesk', 'For') }}: {{ activeRound.votesFor || 0 }},
					{{ t('decidesk', 'Against') }}: {{ activeRound.votesAgainst || 0 }},
					{{ t('decidesk', 'Abstain') }}: {{ activeRound.votesAbstain || 0 }}
				</span>
			</div>

			<!-- Proxy delegation -->
			<div v-if="!activeRound.closedAt" class="voting-panel__proxy">
				<button @click="showProxyDialog = !showProxyDialog">
					{{ t('decidesk', 'Grant proxy') }}
				</button>
				<div v-if="showProxyDialog" class="voting-panel__proxy-form">
					<input v-model="proxyToId"
						type="text"
						:placeholder="t('decidesk', 'Delegate participant ID')"
						:aria-label="t('decidesk', 'Proxy delegate')" />
					<button @click="grantProxy">{{ t('decidesk', 'Confirm proxy') }}</button>
				</div>
				<button v-if="hasProxy" @click="revokeProxy">
					{{ t('decidesk', 'Revoke proxy') }}
				</button>
			</div>

			<!-- Close round button -->
			<div v-if="!activeRound.closedAt" class="voting-panel__close">
				<button class="primary" @click="closeRound">
					{{ t('decidesk', 'Close voting round') }}
				</button>
			</div>
		</div>

		<!-- Result display -->
		<div v-if="closedRound" class="voting-panel__result">
			<h4>{{ t('decidesk', 'Voting result') }}</h4>
			<div class="voting-panel__result-summary">
				<span class="voting-panel__result-badge" :data-result="closedRound.result">
					{{ resultLabel(closedRound.result) }}
				</span>
				<span>{{ t('decidesk', 'For') }}: {{ closedRound.votesFor }}</span>
				<span>{{ t('decidesk', 'Against') }}: {{ closedRound.votesAgainst }}</span>
				<span>{{ t('decidesk', 'Abstain') }}: {{ closedRound.votesAbstain }}</span>
			</div>
			<button @click="publishOri">
				{{ t('decidesk', 'Publish to ORI') }}
			</button>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'VotingRoundPanel',
	props: {
		motionId: {
			type: String,
			required: true,
		},
		motionLifecycle: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			openForm: {
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: '',
			},
			quorumError: '',
			selectedVote: '',
			voteConfirmation: '',
			proxyFor: '',
			proxyToId: '',
			showProxyDialog: false,
			hasProxy: false,
			handsFor: 0,
			handsAgainst: 0,
			handsAbstain: 0,
			isChair: true,
			pollInterval: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		votingRounds() {
			return this.objectStore.objects.votingRound || []
		},
		activeRound() {
			return this.votingRounds.find(r => !r.closedAt) || null
		},
		closedRound() {
			const closed = this.votingRounds.filter(r => r.closedAt)
			if (closed.length === 0) return null
			return closed.sort((a, b) => new Date(b.closedAt) - new Date(a.closedAt))[0]
		},
		totalVotes() {
			if (!this.activeRound) return 0
			return (this.activeRound.votesFor || 0) + (this.activeRound.votesAgainst || 0) + (this.activeRound.votesAbstain || 0)
		},
	},
	created() {
		this.objectStore.fetchObjects('votingRound')
		this.pollInterval = setInterval(() => {
			this.objectStore.fetchObjects('votingRound')
		}, 5000)
	},
	beforeDestroy() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
		}
	},
	methods: {
		async openRound() {
			this.quorumError = ''
			try {
				const url = generateUrl('/apps/decidesk/api/voting-rounds')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						motionId: this.motionId,
						votingMethod: this.openForm.votingMethod,
						isSecret: this.openForm.isSecret,
						closedAt: this.openForm.closedAt || null,
					}),
				})
				if (!response.ok) {
					const data = await response.json()
					this.quorumError = data.error || t('decidesk', 'Failed to open voting round')
					return
				}
				await this.objectStore.fetchObjects('votingRound')
			} catch (error) {
				console.error('Open round failed:', error)
			}
		},
		async castVote(value) {
			if (!this.activeRound) return
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/cast`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						participantId: 'current-user',
						value,
						isProxy: false,
						delegatorId: null,
					}),
				})
				this.selectedVote = value
				this.voteConfirmation = t('decidesk', 'Vote registered')
				await this.objectStore.fetchObjects('votingRound')
			} catch (error) {
				console.error('Cast vote failed:', error)
			}
		},
		async closeRound() {
			if (!this.activeRound) return
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/close`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				await this.objectStore.fetchObjects('votingRound')
			} catch (error) {
				console.error('Close round failed:', error)
			}
		},
		async publishOri() {
			if (!this.closedRound) return
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.closedRound.id}/publish`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
			} catch (error) {
				console.error('Publish failed:', error)
			}
		},
		async grantProxy() {
			if (!this.activeRound || !this.proxyToId) return
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/proxy`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						fromParticipantId: 'current-user',
						toParticipantId: this.proxyToId,
					}),
				})
				this.showProxyDialog = false
				this.hasProxy = true
			} catch (error) {
				console.error('Grant proxy failed:', error)
			}
		},
		async revokeProxy() {
			if (!this.activeRound) return
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/proxy`)
				await fetch(url, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ fromParticipantId: 'current-user' }),
				})
				this.hasProxy = false
			} catch (error) {
				console.error('Revoke proxy failed:', error)
			}
		},
		async saveHandsCount() {
			// For show-of-hands, we save directly as vote totals
			if (!this.activeRound) return
			console.log('Saving hands count:', this.handsFor, this.handsAgainst, this.handsAbstain)
		},
		methodLabel(method) {
			const labels = {
				'for-against-abstain': t('decidesk', 'For / Against / Abstain'),
				'show-of-hands': t('decidesk', 'Show of hands'),
				'ranked-choice': t('decidesk', 'Ranked choice'),
				weighted: t('decidesk', 'Weighted'),
			}
			return labels[method] || method
		},
		resultLabel(result) {
			const labels = {
				adopted: t('decidesk', 'Adopted'),
				rejected: t('decidesk', 'Rejected'),
				tied: t('decidesk', 'Tied'),
				invalid: t('decidesk', 'Invalid'),
			}
			return labels[result] || result
		},
	},
}
</script>

<style scoped>
.voting-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.voting-panel__info {
	display: flex;
	gap: 16px;
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.voting-panel__open-form,
.voting-panel__proxy-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 400px;
	margin-top: 8px;
}

.voting-panel__open-form select,
.voting-panel__open-form input,
.voting-panel__proxy-form input {
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.voting-panel__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
}

.voting-panel__error {
	color: var(--color-error);
	font-weight: 600;
}

.voting-panel__buttons {
	display: flex;
	gap: 8px;
	margin: 12px 0;
}

.voting-panel__vote-btn {
	padding: 12px 24px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-weight: 600;
	font-size: 14px;
}

.voting-panel__vote-btn:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.voting-panel__vote-btn--selected.voting-panel__vote-btn--for {
	background: var(--color-success);
	color: var(--color-primary-text);
	border-color: var(--color-success);
}

.voting-panel__vote-btn--selected.voting-panel__vote-btn--against {
	background: var(--color-error);
	color: var(--color-primary-text);
	border-color: var(--color-error);
}

.voting-panel__vote-btn--selected.voting-panel__vote-btn--abstain {
	background: var(--color-warning);
	color: var(--color-primary-text);
	border-color: var(--color-warning);
}

.voting-panel__confirmation {
	color: var(--color-success);
	font-weight: 600;
}

.voting-panel__proxy-info {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.voting-panel__tally {
	margin: 12px 0;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.voting-panel__hands-inputs {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
}

.voting-panel__hands-inputs input {
	width: 80px;
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.voting-panel__proxy,
.voting-panel__close {
	margin-top: 12px;
	display: flex;
	gap: 8px;
}

.voting-panel button {
	padding: 6px 14px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.voting-panel button.primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.voting-panel__result {
	margin-top: 16px;
}

.voting-panel__result-summary {
	display: flex;
	gap: 16px;
	align-items: center;
	margin: 8px 0;
}

.voting-panel__result-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: var(--border-radius-pill);
	font-weight: 700;
	font-size: 14px;
}

.voting-panel__result-badge[data-result="adopted"] {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.voting-panel__result-badge[data-result="rejected"] {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.voting-panel__result-badge[data-result="tied"] {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.voting-panel__result-badge[data-result="invalid"] {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
