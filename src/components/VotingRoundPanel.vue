<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Stemronde')">
		<!-- Open round dialog (task-6.3) -->
		<div v-if="canOpenRound && motionLifecycle === 'debating'" class="decidesk-voting-open">
			<NcButton
				type="primary"
				:aria-label="t('decidesk', 'Open voting round')"
				@click="showOpenDialog = true">
				{{ t('decidesk', 'Stemronde openen') }}
			</NcButton>
		</div>

		<!-- Open round form -->
		<div v-if="showOpenDialog" class="decidesk-voting-form">
			<label for="voting-method">{{ t('decidesk', 'Stemmethode') }}</label>
			<select
				id="voting-method"
				v-model="newRound.votingMethod"
				:aria-label="t('decidesk', 'Voting method')">
				<option value="for-against-abstain">
					{{ t('decidesk', 'Voor / Tegen / Onthouding') }}
				</option>
				<option value="show-of-hands">
					{{ t('decidesk', 'Handopsteking') }}
				</option>
				<option value="weighted">
					{{ t('decidesk', 'Gewogen') }}
				</option>
				<option value="ranked-choice">
					{{ t('decidesk', 'Voorkeursstemming') }}
				</option>
			</select>

			<label>
				<input v-model="newRound.isSecret" type="checkbox" :aria-label="t('decidesk', 'Secret ballot')">
				{{ t('decidesk', 'Geheime stemming') }}
			</label>

			<label for="voting-deadline">{{ t('decidesk', 'Stemdeadline (optioneel)') }}</label>
			<input
				id="voting-deadline"
				v-model="newRound.closedAt"
				type="datetime-local"
				:aria-label="t('decidesk', 'Voting deadline')">

			<div v-if="quorumError" class="decidesk-error" role="alert">
				{{ quorumError }}
			</div>

			<div class="decidesk-voting-form-actions">
				<NcButton type="primary" :aria-label="t('decidesk', 'Open round')" @click="submitOpenRound">
					{{ t('decidesk', 'Openen') }}
				</NcButton>
				<NcButton type="secondary" :aria-label="t('decidesk', 'Cancel')" @click="showOpenDialog = false">
					{{ t('decidesk', 'Annuleren') }}
				</NcButton>
			</div>
		</div>

		<!-- Current open round -->
		<div v-if="openRound" class="decidesk-voting-open-round">
			<p class="decidesk-voting-status">
				<strong>{{ t('decidesk', 'Stemronde open') }}</strong>
				— {{ t('decidesk', 'Stemmethode') }}: {{ openRound.votingMethod }}
			</p>

			<!-- Show-of-hands manual entry (task-6.6) -->
			<div v-if="openRound.votingMethod === 'show-of-hands' && canOpenRound" class="decidesk-show-of-hands">
				<h4>{{ t('decidesk', 'Handopsteking resultaat') }}</h4>
				<label for="soh-for">{{ t('decidesk', 'Voor') }}</label>
				<input id="soh-for"
					v-model.number="showOfHands.for"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes for')">
				<label for="soh-against">{{ t('decidesk', 'Tegen') }}</label>
				<input id="soh-against"
					v-model.number="showOfHands.against"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes against')">
				<label for="soh-abstain">{{ t('decidesk', 'Onthouding') }}</label>
				<input id="soh-abstain"
					v-model.number="showOfHands.abstain"
					type="number"
					min="0"
					:aria-label="t('decidesk', 'Votes abstain')">
				<NcButton type="secondary" :aria-label="t('decidesk', 'Save show-of-hands result')" @click="saveShowOfHands">
					{{ t('decidesk', 'Resultaat opslaan') }}
				</NcButton>
			</div>

			<!-- Vote buttons (task-6.1, task-7.3) -->
			<div v-if="openRound.votingMethod !== 'show-of-hands'" class="decidesk-vote-buttons">
				<!-- Proxy indicator -->
				<p v-if="activeProxy" class="decidesk-proxy-notice">
					{{ t('decidesk', 'U stemt namens:') }} {{ activeProxy }}
				</p>

				<NcButton
					v-if="!voteCast"
					type="success"
					:aria-label="t('decidesk', 'Vote for')"
					@click="castVote('for')">
					{{ t('decidesk', 'Voor') }}
				</NcButton>
				<NcButton
					v-if="!voteCast"
					type="error"
					:aria-label="t('decidesk', 'Vote against')"
					@click="castVote('against')">
					{{ t('decidesk', 'Tegen') }}
				</NcButton>
				<NcButton
					v-if="!voteCast"
					type="secondary"
					:aria-label="t('decidesk', 'Abstain')"
					@click="castVote('abstain')">
					{{ t('decidesk', 'Onthouding') }}
				</NcButton>
				<p v-if="voteCast" class="decidesk-vote-confirmation" role="status">
					{{ t('decidesk', 'Uw stem is geregistreerd') }}: <strong>{{ voteCast }}</strong>
				</p>
			</div>

			<!-- Live tally for chair/secretary (task-6.2) -->
			<div v-if="canOpenRound && tally" class="decidesk-live-tally">
				<p>
					{{ t('decidesk', 'Uitgebracht') }}: {{ tally.total }}
					— {{ t('decidesk', 'Voor') }}: {{ tally.votesFor }},
					{{ t('decidesk', 'Tegen') }}: {{ tally.votesAgainst }},
					{{ t('decidesk', 'Onthouding') }}: {{ tally.votesAbstain }}
				</p>
			</div>

			<!-- Proxy delegation section (task-7.1) -->
			<div v-if="!canOpenRound" class="decidesk-proxy-section">
				<NcButton
					v-if="!activeProxy"
					type="secondary"
					:aria-label="t('decidesk', 'Grant proxy')"
					@click="showProxyDialog = true">
					{{ t('decidesk', 'Volmacht verlenen') }}
				</NcButton>
				<!-- Revoke proxy (task-7.2) -->
				<NcButton
					v-if="activeProxy && !roundIsOpen"
					type="error"
					:aria-label="t('decidesk', 'Revoke proxy')"
					@click="revokeProxy">
					{{ t('decidesk', 'Volmacht intrekken') }}
				</NcButton>
			</div>

			<!-- Close round button (task-6.4) -->
			<NcButton
				v-if="canOpenRound"
				type="error"
				:aria-label="t('decidesk', 'Close voting round')"
				@click="showCloseConfirm = true">
				{{ t('decidesk', 'Stemronde sluiten') }}
			</NcButton>

			<div v-if="showCloseConfirm"
				class="decidesk-close-confirm"
				role="dialog"
				:aria-label="t('decidesk', 'Confirm close')">
				<p>{{ t('decidesk', 'Stemronde sluiten?') }}</p>
				<NcButton type="primary" :aria-label="t('decidesk', 'Confirm')" @click="closeRound">
					{{ t('decidesk', 'Bevestigen') }}
				</NcButton>
				<NcButton type="secondary" :aria-label="t('decidesk', 'Cancel')" @click="showCloseConfirm = false">
					{{ t('decidesk', 'Annuleren') }}
				</NcButton>
			</div>
		</div>

		<!-- Closed round result (task-6.5) -->
		<div v-if="closedRound" class="decidesk-round-result">
			<h4>{{ t('decidesk', 'Stemresultaat') }}</h4>
			<p>
				<strong :class="resultClass">{{ resultLabel }}</strong>
			</p>
			<p>
				{{ t('decidesk', 'Voor') }}: {{ closedRound.votesFor }} |
				{{ t('decidesk', 'Tegen') }}: {{ closedRound.votesAgainst }} |
				{{ t('decidesk', 'Onthouding') }}: {{ closedRound.votesAbstain }}
			</p>
			<NcButton
				v-if="canOpenRound && !oriPublished"
				type="secondary"
				:aria-label="t('decidesk', 'Publish to ORI')"
				@click="publishToOri">
				{{ t('decidesk', 'Publiceren naar ORI') }}
			</NcButton>
			<p v-if="oriPublished" class="decidesk-published">
				{{ t('decidesk', 'Publicatie in behandeling') }}
			</p>
		</div>

		<!-- No round yet -->
		<p v-if="!openRound && !closedRound" class="decidesk-empty">
			{{ t('decidesk', 'Geen actieve stemronde.') }}
		</p>
	</CnDetailCard>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'VotingRoundPanel',
	/**
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1
	 */
	components: { NcButton, CnDetailCard },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		objectStore: { type: Object, required: true },
	},
	data() {
		return {
			showOpenDialog: false,
			showCloseConfirm: false,
			showProxyDialog: false,
			quorumError: null,
			voteCast: null,
			oriPublished: false,
			tally: null,
			activeProxy: null,
			roundIsOpen: false,
			showOfHands: { for: 0, against: 0, abstain: 0 },
			newRound: {
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: '',
			},
			pollInterval: null,
		}
	},
	computed: {
		allRounds() {
			const rounds = this.objectStore.getObjects('voting-round') ?? []
			return rounds.filter(r => {
				const motionRel = r.relations?.motion ?? []
				return motionRel.some(rel => (rel.id ?? rel) === this.motionId)
			})
		},
		openRound() {
			return this.allRounds.find(r => r.openedAt && !r.closedAt) ?? null
		},
		closedRound() {
			if (this.openRound) return null
			return this.allRounds
				.filter(r => r.closedAt)
				.sort((a, b) => new Date(b.closedAt) - new Date(a.closedAt))[0] ?? null
		},
		canOpenRound() {
			// In a real app, check user role from settings store. Simplified here.
			return true
		},
		resultLabel() {
			if (!this.closedRound) return ''
			const labels = {
				adopted: this.t('decidesk', 'Aangenomen'),
				rejected: this.t('decidesk', 'Verworpen'),
				tied: this.t('decidesk', 'Gelijk'),
				invalid: this.t('decidesk', 'Ongeldig'),
			}
			return labels[this.closedRound.result] ?? this.closedRound.result
		},
		resultClass() {
			if (!this.closedRound) return ''
			return {
				'decidesk-result--adopted': this.closedRound.result === 'adopted',
				'decidesk-result--rejected': this.closedRound.result === 'rejected',
				'decidesk-result--tied': this.closedRound.result === 'tied',
			}
		},
	},
	mounted() {
		this.objectStore.fetchObjects('voting-round')
		// Live tally polling every 5 seconds (task-6.2).
		this.pollInterval = setInterval(() => {
			if (this.openRound) {
				this.objectStore.fetchObjects('voting-round')
				this.tally = {
					total: (this.openRound.votesFor ?? 0) + (this.openRound.votesAgainst ?? 0) + (this.openRound.votesAbstain ?? 0),
					votesFor: this.openRound.votesFor ?? 0,
					votesAgainst: this.openRound.votesAgainst ?? 0,
					votesAbstain: this.openRound.votesAbstain ?? 0,
				}
			}
		}, 5000)
	},
	beforeDestroy() {
		if (this.pollInterval) clearInterval(this.pollInterval)
	},
	methods: {
		async submitOpenRound() {
			this.quorumError = null
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				const response = await fetch(`${appBaseUrl}/api/voting-rounds`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						motionId: this.motionId,
						votingMethod: this.newRound.votingMethod,
						isSecret: this.newRound.isSecret,
						closedAt: this.newRound.closedAt || null,
					}),
				})
				const data = await response.json()
				if (!response.ok) {
					this.quorumError = data.message || this.t('decidesk', 'Stemronde openen mislukt.')
					return
				}
				this.showOpenDialog = false
				this.objectStore.fetchObjects('voting-round')
			} catch (e) {
				this.quorumError = this.t('decidesk', 'Verbindingsfout.')
			}
		},
		async castVote(value) {
			const round = this.openRound
			if (!round) return
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				const response = await fetch(`${appBaseUrl}/api/voting-rounds/${round.id}/cast`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						participantId: getCurrentUser()?.uid,
						value,
						isProxy: false,
					}),
				})
				if (response.ok) {
					this.voteCast = value
				}
			} catch (e) {
				console.error('Vote cast failed', e)
			}
		},
		async closeRound() {
			const round = this.openRound
			if (!round) return
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				await fetch(`${appBaseUrl}/api/voting-rounds/${round.id}/close`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
				})
				this.showCloseConfirm = false
				this.objectStore.fetchObjects('voting-round')
			} catch (e) {
				console.error('Close round failed', e)
			}
		},
		async publishToOri() {
			const round = this.closedRound
			if (!round) return
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				await fetch(`${appBaseUrl}/api/voting-rounds/${round.id}/publish`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
				})
				this.oriPublished = true
			} catch (e) {
				console.error('ORI publish failed', e)
			}
		},
		async saveShowOfHands() {
			const round = this.openRound
			if (!round) return
			// Save totals directly via objectStore.
			const updated = {
				...round,
				votesFor: this.showOfHands.for,
				votesAgainst: this.showOfHands.against,
				votesAbstain: this.showOfHands.abstain,
			}
			await this.objectStore.saveObject('voting-round', updated)
			this.objectStore.fetchObjects('voting-round')
		},
		async revokeProxy() {
			const round = this.openRound
			if (!round) return
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				await fetch(`${appBaseUrl}/api/voting-rounds/${round.id}/proxy`, {
					method: 'DELETE',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ fromParticipantId: getCurrentUser()?.uid }),
				})
				this.activeProxy = null
			} catch (e) {
				console.error('Proxy revoke failed', e)
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

.decidesk-voting-form {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-voting-form label {
	font-weight: bold;
}

.decidesk-voting-form select,
.decidesk-voting-form input[type="datetime-local"] {
	width: 100%;
	padding: var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.decidesk-voting-form-actions {
	display: flex;
	gap: var(--default-grid-baseline);
}

.decidesk-vote-buttons {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin: var(--default-grid-baseline) 0;
}

.decidesk-vote-confirmation {
	width: 100%;
	color: var(--color-success);
	font-weight: bold;
}

.decidesk-live-tally {
	background: var(--color-background-dark);
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline) 0;
}

.decidesk-proxy-notice {
	color: var(--color-primary);
	font-style: italic;
}

.decidesk-proxy-section {
	margin-top: var(--default-grid-baseline);
}

.decidesk-close-confirm {
	background: var(--color-background-dark);
	padding: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
	margin-top: var(--default-grid-baseline);
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: center;
}

.decidesk-round-result {
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.decidesk-result--adopted {
	color: var(--color-success);
}

.decidesk-result--rejected {
	color: var(--color-error);
}

.decidesk-result--tied {
	color: var(--color-warning);
}

.decidesk-published {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.decidesk-voting-open {
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-voting-status {
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-show-of-hands {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-show-of-hands input[type="number"] {
	width: 80px;
	padding: var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.decidesk-error {
	color: var(--color-error);
	font-weight: bold;
}
</style>
