<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
-->
<template>
	<CnDetailCard :title="t('decidesk', 'Voting Round')">
		<!-- Open round: vote casting -->
		<div v-if="openRound">
			<!-- Proxy status for delegate -->
			<div
				v-if="activeProxy"
				class="decidesk-proxy-status"
				role="status"
				aria-live="polite">
				{{ t('decidesk', 'U stemt namens') }}: <strong>{{ activeProxy.delegatorId }}</strong>
			</div>

			<!-- Show-of-hands entry (chair only) -->
			<div v-if="openRound.votingMethod === 'show-of-hands'" class="decidesk-show-of-hands">
				<h4>{{ t('decidesk', 'Handopsteking') }}</h4>
				<div class="decidesk-hands-inputs">
					<label>
						{{ t('decidesk', 'Voor') }}:
						<input
							v-model.number="handsForm.for"
							type="number"
							min="0"
							:aria-label="t('decidesk', 'Votes for')" />
					</label>
					<label>
						{{ t('decidesk', 'Tegen') }}:
						<input
							v-model.number="handsForm.against"
							type="number"
							min="0"
							:aria-label="t('decidesk', 'Votes against')" />
					</label>
					<label>
						{{ t('decidesk', 'Onthouding') }}:
						<input
							v-model.number="handsForm.abstain"
							type="number"
							min="0"
							:aria-label="t('decidesk', 'Votes abstain')" />
					</label>
				</div>
				<NcButton
					type="primary"
					:aria-label="t('decidesk', 'Save show-of-hands result')"
					@click="saveShowOfHands">
					{{ t('decidesk', 'Resultaat opslaan') }}
				</NcButton>
			</div>

			<!-- Regular vote casting buttons -->
			<div
				v-else-if="!voteCast"
				class="decidesk-vote-buttons"
				role="group"
				:aria-label="t('decidesk', 'Cast your vote')">
				<NcButton
					type="primary"
					:aria-label="t('decidesk', 'Vote for')"
					:aria-pressed="lastVote === 'for'"
					@click="castVote('for')">
					{{ t('decidesk', 'Voor') }}
				</NcButton>
				<NcButton
					type="error"
					:aria-label="t('decidesk', 'Vote against')"
					:aria-pressed="lastVote === 'against'"
					@click="castVote('against')">
					{{ t('decidesk', 'Tegen') }}
				</NcButton>
				<NcButton
					type="secondary"
					:aria-label="t('decidesk', 'Abstain')"
					:aria-pressed="lastVote === 'abstain'"
					@click="castVote('abstain')">
					{{ t('decidesk', 'Onthouding') }}
				</NcButton>
			</div>

			<!-- Confirmation after vote -->
			<div
				v-else
				class="decidesk-vote-confirmed"
				role="status"
				aria-live="polite">
				{{ t('decidesk', 'Stem uitgebracht') }}: <strong>{{ lastVote }}</strong>
			</div>

			<!-- Live tally (chair/secretary only) -->
			<div v-if="isChairOrSecretary" class="decidesk-live-tally" aria-label="Live tally">
				<span>{{ t('decidesk', 'Uitgebracht') }}: {{ tally.total }} &mdash;</span>
				<span>{{ t('decidesk', 'Voor') }}: {{ tally.for }},</span>
				<span>{{ t('decidesk', 'Tegen') }}: {{ tally.against }},</span>
				<span>{{ t('decidesk', 'Onthouding') }}: {{ tally.abstain }}</span>
			</div>
			<div v-else class="decidesk-live-tally-member">
				{{ t('decidesk', 'Uitgebracht') }}: {{ tally.total }}
			</div>

			<!-- Proxy grant section (before round opens, but round is already open here) -->
			<div class="decidesk-proxy-actions">
				<NcButton
					type="secondary"
					:aria-label="t('decidesk', 'Grant proxy vote')"
					@click="showProxyDialog = true">
					{{ t('decidesk', 'Volmacht verlenen') }}
				</NcButton>
			</div>

			<!-- Close round button (chair/secretary) -->
			<NcButton
				v-if="isChairOrSecretary"
				type="error"
				:aria-label="t('decidesk', 'Close voting round')"
				@click="showCloseDialog = true">
				{{ t('decidesk', 'Stemronde sluiten') }}
			</NcButton>

			<!-- Close round confirmation dialog -->
			<NcDialog
				v-if="showCloseDialog"
				:name="t('decidesk', 'Stemronde sluiten')"
				@closing="showCloseDialog = false">
				<p>{{ t('decidesk', 'Stemronde sluiten?') }}</p>
				<template #actions>
					<NcButton type="error" :aria-label="t('decidesk', 'Confirm close')" @click="closeRound">
						{{ t('decidesk', 'Sluiten') }}
					</NcButton>
					<NcButton type="secondary" @click="showCloseDialog = false">
						{{ t('decidesk', 'Annuleren') }}
					</NcButton>
				</template>
			</NcDialog>
		</div>

		<!-- No open round: show open-round dialog or last result -->
		<div v-else>
			<!-- Result from last closed round -->
			<div v-if="closedRound" class="decidesk-round-result">
				<h4>{{ t('decidesk', 'Uitslag') }}</h4>
				<div
					class="decidesk-result-badge"
					:class="`decidesk-result-${closedRound.result}`"
					role="status">
					{{ resultLabel(closedRound.result) }}
				</div>
				<p>
					{{ t('decidesk', 'Voor') }}: {{ closedRound.votesFor }},
					{{ t('decidesk', 'Tegen') }}: {{ closedRound.votesAgainst }},
					{{ t('decidesk', 'Onthouding') }}: {{ closedRound.votesAbstain }}
				</p>
				<NcButton
					v-if="isChairOrSecretary"
					type="secondary"
					:aria-label="t('decidesk', 'Publish to ORI')"
					@click="publishToOri">
					{{ t('decidesk', 'Publiceren naar ORI') }}
				</NcButton>
				<span v-if="oriStatus" class="decidesk-ori-status">{{ oriStatusLabel }}</span>
			</div>

			<!-- Open round dialog (chair/secretary, motion in debating state) -->
			<div v-if="isChairOrSecretary && motionInDebating" class="decidesk-open-round">
				<NcButton
					type="primary"
					:aria-label="t('decidesk', 'Open voting round')"
					@click="showOpenDialog = true">
					{{ t('decidesk', 'Stemronde openen') }}
				</NcButton>
			</div>

			<NcDialog
				v-if="showOpenDialog"
				:name="t('decidesk', 'Stemronde openen')"
				@closing="showOpenDialog = false">
				<div class="decidesk-open-dialog">
					<label>
						{{ t('decidesk', 'Stemmethode') }}:
						<select
							v-model="openForm.votingMethod"
							:aria-label="t('decidesk', 'Voting method')">
							<option value="for-against-abstain">{{ t('decidesk', 'Voor / Tegen / Onthouding') }}</option>
							<option value="show-of-hands">{{ t('decidesk', 'Handopsteking') }}</option>
							<option value="weighted">{{ t('decidesk', 'Gewogen') }}</option>
							<option value="ranked-choice">{{ t('decidesk', 'Voorkeursstemming') }}</option>
						</select>
					</label>
					<label class="decidesk-toggle">
						<input
							v-model="openForm.isSecret"
							type="checkbox"
							:aria-label="t('decidesk', 'Secret ballot')" />
						{{ t('decidesk', 'Geheime stemming') }}
					</label>
					<label>
						{{ t('decidesk', 'Sluitingstijd (optioneel)') }}:
						<input
							v-model="openForm.closedAt"
							type="datetime-local"
							:aria-label="t('decidesk', 'Closing deadline')" />
					</label>
					<p v-if="openError" class="decidesk-error" role="alert">{{ openError }}</p>
				</div>
				<template #actions>
					<NcButton type="primary" @click="openRound">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
				</template>
			</NcDialog>
		</div>

		<!-- Proxy grant dialog -->
		<NcDialog
			v-if="showProxyDialog"
			:name="t('decidesk', 'Volmacht verlenen')"
			@closing="showProxyDialog = false">
			<div class="decidesk-proxy-dialog">
				<NcInputField
					v-model="proxyForm.fromParticipantId"
					:label="t('decidesk', 'From participant ID')"
					:placeholder="t('decidesk', 'Delegating participant')" />
				<NcInputField
					v-model="proxyForm.toParticipantId"
					:label="t('decidesk', 'To participant ID')"
					:placeholder="t('decidesk', 'Delegate participant')" />
			</div>
			<template #actions>
				<NcButton type="primary" @click="grantProxy">
					{{ t('decidesk', 'Verlenen') }}
				</NcButton>
				<NcButton
					v-if="activeProxy"
					type="error"
					@click="revokeProxy">
					{{ t('decidesk', 'Volmacht intrekken') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcInputField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useObjectStore } from '../store/store.js'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
 */
export default {
	name: 'VotingRoundPanel',
	components: { CnDetailCard, NcButton, NcDialog, NcInputField },
	props: {
		motionId: { type: String, required: true },
		meetingId: { type: String, default: null },
		motionType: { type: String, default: 'motion' },
	},
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},
	data() {
		return {
			openRound: null,
			closedRound: null,
			voteCast: false,
			lastVote: null,
			tally: { for: 0, against: 0, abstain: 0, total: 0 },
			showOpenDialog: false,
			showCloseDialog: false,
			showProxyDialog: false,
			openForm: { votingMethod: 'for-against-abstain', isSecret: false, closedAt: '' },
			openError: null,
			handsForm: { for: 0, against: 0, abstain: 0 },
			proxyForm: { fromParticipantId: '', toParticipantId: '' },
			activeProxy: null,
			oriStatus: null,
			pollInterval: null,
		}
	},
	computed: {
		isChairOrSecretary() {
			// In a real implementation check user roles from the store.
			return true
		},
		motionInDebating() {
			return true // caller passes motion prop; simplified here
		},
		oriStatusLabel() {
			if (this.oriStatus === 'published') return this.t('decidesk', 'Gepubliceerd')
			if (this.oriStatus === 'not_configured') return this.t('decidesk', 'ORI niet geconfigureerd')
			return this.t('decidesk', 'Publicatie in behandeling')
		},
	},
	mounted() {
		this.fetchRounds()
		// Poll tally every 5 seconds when a round is open.
		this.pollInterval = setInterval(() => {
			if (this.openRound) this.fetchTally()
		}, 5000)
	},
	beforeDestroy() {
		clearInterval(this.pollInterval)
	},
	methods: {
		async fetchRounds() {
			if (!this.motionId) return
			try {
				const rounds = await this.objectStore.fetchObjects('voting-round', { motionId: this.motionId })
				const open = (rounds ?? []).find(r => !r.closedAt && r.status === 'open')
				const closed = (rounds ?? [])
					.filter(r => r.closedAt)
					.sort((a, b) => new Date(b.closedAt) - new Date(a.closedAt))[0]
				this.openRound = open ?? null
				this.closedRound = closed ?? null
			} catch (e) {
				console.error('Failed to fetch voting rounds', e)
			}
		},
		async fetchTally() {
			if (!this.openRound?.id) return
			try {
				const round = await this.objectStore.getObject('voting-round', this.openRound.id)
				if (round) {
					this.tally = {
						for: round.votesFor ?? 0,
						against: round.votesAgainst ?? 0,
						abstain: round.votesAbstain ?? 0,
						total: (round.votesFor ?? 0) + (round.votesAgainst ?? 0) + (round.votesAbstain ?? 0),
					}
				}
			} catch (e) {
				// Silent failure for live tally polling.
			}
		},
		async castVote(value) {
			if (!this.openRound?.id) return
			const participantId = window.OC?.currentUser ?? ''
			try {
				await axios.post(
					generateUrl(`/apps/decidesk/api/voting-rounds/${this.openRound.id}/cast`),
					{ participantId, value, isProxy: false }
				)
				this.voteCast = true
				this.lastVote = value
				await this.fetchTally()

				// Also cast proxy vote if delegate has a pending proxy.
				if (this.activeProxy) {
					await axios.post(
						generateUrl(`/apps/decidesk/api/voting-rounds/${this.openRound.id}/cast`),
						{
							participantId,
							value,
							isProxy: true,
							delegatorId: this.activeProxy.delegatorId,
						}
					)
				}
			} catch (e) {
				console.error('Failed to cast vote', e)
			}
		},
		async saveShowOfHands() {
			if (!this.openRound?.id) return
			try {
				// Save totals directly on the round object.
				await this.objectStore.saveObject('voting-round', {
					...this.openRound,
					votesFor: this.handsForm.for,
					votesAgainst: this.handsForm.against,
					votesAbstain: this.handsForm.abstain,
				})
				await this.fetchTally()
			} catch (e) {
				console.error('Failed to save show-of-hands', e)
			}
		},
		async openRound() {
			this.openError = null
			try {
				await axios.post(
					generateUrl('/apps/decidesk/api/voting-rounds'),
					{
						motionId: this.motionId,
						meetingId: this.meetingId ?? '',
						votingMethod: this.openForm.votingMethod,
						isSecret: this.openForm.isSecret,
						closedAt: this.openForm.closedAt || null,
					}
				)
				this.showOpenDialog = false
				await this.fetchRounds()
			} catch (e) {
				this.openError = e.response?.data?.error ?? this.t('decidesk', 'Failed to open voting round')
			}
		},
		async closeRound() {
			if (!this.openRound?.id) return
			try {
				await axios.post(generateUrl(`/apps/decidesk/api/voting-rounds/${this.openRound.id}/close`))
				this.showCloseDialog = false
				await this.fetchRounds()
			} catch (e) {
				console.error('Failed to close round', e)
			}
		},
		async publishToOri() {
			if (!this.closedRound?.id) return
			try {
				const resp = await axios.post(generateUrl(`/apps/decidesk/api/voting-rounds/${this.closedRound.id}/publish`))
				this.oriStatus = resp.data.status
			} catch (e) {
				console.error('ORI publish failed', e)
			}
		},
		async grantProxy() {
			if (!this.openRound?.id && !this.motionId) return
			const roundId = this.openRound?.id ?? ''
			if (!roundId) return
			try {
				await axios.post(
					generateUrl(`/apps/decidesk/api/voting-rounds/${roundId}/proxy`),
					this.proxyForm
				)
				this.showProxyDialog = false
				this.activeProxy = { delegatorId: this.proxyForm.fromParticipantId }
			} catch (e) {
				console.error('Grant proxy failed', e)
			}
		},
		async revokeProxy() {
			if (!this.openRound?.id) return
			try {
				await axios.delete(
					generateUrl(`/apps/decidesk/api/voting-rounds/${this.openRound.id}/proxy`),
					{ data: { fromParticipantId: this.proxyForm.fromParticipantId } }
				)
				this.activeProxy = null
				this.showProxyDialog = false
			} catch (e) {
				console.error('Revoke proxy failed', e)
			}
		},
		resultLabel(result) {
			const map = {
				adopted: this.t('decidesk', 'Aangenomen'),
				rejected: this.t('decidesk', 'Verworpen'),
				tied: this.t('decidesk', 'Gelijk'),
				invalid: this.t('decidesk', 'Ongeldig'),
			}
			return map[result] ?? result
		},
	},
}
</script>

<style scoped>
.decidesk-vote-buttons {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	flex-wrap: wrap;
	margin-bottom: 1rem;
}

.decidesk-live-tally,
.decidesk-live-tally-member {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin: 0.5rem 0;
}

.decidesk-proxy-status {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 0.4rem 0.75rem;
	margin-bottom: 0.75rem;
	font-size: 0.9rem;
}

.decidesk-proxy-actions {
	margin: 0.5rem 0;
}

.decidesk-result-badge {
	display: inline-block;
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	font-weight: bold;
	margin-bottom: 0.5rem;
}

.decidesk-result-adopted { background: var(--color-success); color: #fff; }
.decidesk-result-rejected { background: var(--color-error); color: #fff; }
.decidesk-result-tied { background: var(--color-warning); }
.decidesk-result-invalid { background: var(--color-background-dark); }

.decidesk-vote-confirmed {
	padding: 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	margin-bottom: 0.5rem;
}

.decidesk-show-of-hands h4 { margin: 0 0 0.5rem; }

.decidesk-hands-inputs {
	display: flex;
	gap: 1rem;
	flex-wrap: wrap;
	margin-bottom: 0.75rem;
}

.decidesk-hands-inputs label {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.decidesk-hands-inputs input {
	width: 5rem;
}

.decidesk-open-dialog,
.decidesk-proxy-dialog {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0.5rem 0;
}

.decidesk-open-dialog select {
	width: 100%;
}

.decidesk-toggle {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.decidesk-error {
	color: var(--color-error);
}

.decidesk-ori-status {
	margin-left: 0.5rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.decidesk-open-round {
	margin-top: 0.5rem;
}
</style>
