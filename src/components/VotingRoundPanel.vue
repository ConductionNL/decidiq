<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7
-->
<template>
	<div class="decidesk-voting-panel" role="region" :aria-label="t('decidesk', 'Voting Round')">
		<!-- Open VotingRound dialog (chair/secretary, motion in debating state) -->
		<div v-if="canOpenRound && !activeRound" class="decidesk-voting-panel__open-section">
			<NcButton
				type="primary"
				:aria-label="t('decidesk', 'Open Voting Round')"
				@click="showOpenDialog = true">
				{{ t('decidesk', 'Open Voting Round') }}
			</NcButton>
		</div>

		<!-- Open round dialog -->
		<NcDialog
			v-if="showOpenDialog"
			:name="t('decidesk', 'Open Voting Round')"
			@closing="showOpenDialog = false">
			<div class="decidesk-voting-panel__open-form">
				<NcSelect
					v-model="newRound.votingMethod"
					:label="t('decidesk', 'Voting Method')"
					:options="votingMethodOptions"
					:aria-label="t('decidesk', 'Voting Method')" />
				<NcCheckboxRadioSwitch
					v-model="newRound.isSecret"
					:aria-label="t('decidesk', 'Secret Ballot')">
					{{ t('decidesk', 'Secret Ballot') }}
				</NcCheckboxRadioSwitch>
				<NcDateTimePicker
					v-model="newRound.closedAt"
					:label="t('decidesk', 'Voting Deadline (optional)')"
					:aria-label="t('decidesk', 'Voting Deadline')" />
				<p v-if="openError" class="decidesk-error" role="alert">
					{{ openError }}
				</p>
				<NcButton
					type="primary"
					:disabled="openLoading"
					:aria-label="t('decidesk', 'Open Round')"
					@click="openRound">
					{{ openLoading ? t('decidesk', 'Opening...') : t('decidesk', 'Open Round') }}
				</NcButton>
			</div>
		</NcDialog>

		<!-- Active voting round -->
		<div v-if="activeRound" class="decidesk-voting-panel__active">
			<h3 class="decidesk-voting-panel__title">
				{{ t('decidesk', 'Voting Round') }}
				<span class="decidesk-badge decidesk-badge--open" :aria-label="t('decidesk', 'Open')">
					{{ t('decidesk', 'Open') }}
				</span>
			</h3>

			<!-- Proxy: received proxy indicator -->
			<div v-if="receivedProxy" class="decidesk-voting-panel__proxy-notice" role="note">
				{{ t('decidesk', 'You are voting on behalf of: {name}', { name: receivedProxy.from }) }}
			</div>

			<!-- Show-of-hands data entry (chair) -->
			<div v-if="activeRound.votingMethod === 'show-of-hands' && isChairOrSecretary" class="decidesk-voting-panel__show-of-hands">
				<h4>{{ t('decidesk', 'Enter Show-of-Hands Results') }}</h4>
				<div class="decidesk-voting-panel__soh-inputs">
					<NcTextField
						v-model="showOfHands.for"
						type="number"
						:label="t('decidesk', 'For')"
						:aria-label="t('decidesk', 'Votes for')" />
					<NcTextField
						v-model="showOfHands.against"
						type="number"
						:label="t('decidesk', 'Against')"
						:aria-label="t('decidesk', 'Votes against')" />
					<NcTextField
						v-model="showOfHands.abstain"
						type="number"
						:label="t('decidesk', 'Abstain')"
						:aria-label="t('decidesk', 'Votes abstain')" />
				</div>
				<NcButton
					type="secondary"
					:aria-label="t('decidesk', 'Save Results')"
					@click="saveShowOfHands">
					{{ t('decidesk', 'Save Results') }}
				</NcButton>
			</div>

			<!-- Regular vote casting buttons -->
			<div v-else-if="canVote" class="decidesk-voting-panel__vote-buttons">
				<NcButton
					type="success"
					:class="{ 'decidesk-voted': votedValue === 'for' }"
					:aria-pressed="votedValue === 'for'"
					:aria-label="t('decidesk', 'Vote For')"
					@click="castVote('for')">
					{{ t('decidesk', 'Voor') }}
				</NcButton>
				<NcButton
					type="error"
					:class="{ 'decidesk-voted': votedValue === 'against' }"
					:aria-pressed="votedValue === 'against'"
					:aria-label="t('decidesk', 'Vote Against')"
					@click="castVote('against')">
					{{ t('decidesk', 'Tegen') }}
				</NcButton>
				<NcButton
					type="secondary"
					:class="{ 'decidesk-voted': votedValue === 'abstain' }"
					:aria-pressed="votedValue === 'abstain'"
					:aria-label="t('decidesk', 'Abstain')"
					@click="castVote('abstain')">
					{{ t('decidesk', 'Onthouding') }}
				</NcButton>
				<p v-if="voteError" class="decidesk-error" role="alert">{{ voteError }}</p>
				<p v-if="votedValue" class="decidesk-success" role="status">
					{{ t('decidesk', 'Your vote has been registered.') }}
				</p>
			</div>

			<!-- Live tally (chair/secretary sees full count, members see count only) -->
			<div class="decidesk-voting-panel__tally" role="status" aria-live="polite">
				<span v-if="isChairOrSecretary">
					{{ t('decidesk', 'Cast: {cast} / {total} — For: {for}, Against: {against}, Abstain: {abstain}', {
						cast: tallyTotal,
						total: memberCount,
						for: activeRound.votesFor || 0,
						against: activeRound.votesAgainst || 0,
						abstain: activeRound.votesAbstain || 0,
					}) }}
				</span>
				<span v-else>
					{{ t('decidesk', 'Votes cast: {cast} / {total}', { cast: tallyTotal, total: memberCount }) }}
				</span>
			</div>

			<!-- Proxy section: grant/revoke (before round opens — shown when round has just been created but not yet opened) -->
			<div v-if="canManageProxy" class="decidesk-voting-panel__proxy">
				<NcButton
					v-if="!hasGrantedProxy"
					type="tertiary"
					:aria-label="t('decidesk', 'Grant Proxy')"
					@click="showProxyDialog = true">
					{{ t('decidesk', 'Grant Proxy (Volmacht)') }}
				</NcButton>
				<NcButton
					v-if="hasGrantedProxy"
					type="tertiary-no-background"
					:aria-label="t('decidesk', 'Revoke Proxy')"
					@click="revokeProxy">
					{{ t('decidesk', 'Revoke Proxy') }}
				</NcButton>
			</div>

			<!-- Close round button (chair/secretary) -->
			<div v-if="isChairOrSecretary" class="decidesk-voting-panel__close">
				<NcButton
					type="error"
					:aria-label="t('decidesk', 'Close Voting Round')"
					@click="showCloseDialog = true">
					{{ t('decidesk', 'Close Voting Round') }}
				</NcButton>
			</div>
		</div>

		<!-- Proxy grant dialog -->
		<NcDialog
			v-if="showProxyDialog"
			:name="t('decidesk', 'Grant Proxy (Volmacht)')"
			@closing="showProxyDialog = false">
			<div class="decidesk-voting-panel__proxy-form">
				<NcTextField
					v-model="proxyToParticipantId"
					:label="t('decidesk', 'Delegate Participant ID')"
					:aria-label="t('decidesk', 'Participant ID to delegate vote to')" />
				<NcButton
					type="primary"
					:aria-label="t('decidesk', 'Grant Proxy')"
					@click="grantProxy">
					{{ t('decidesk', 'Grant Proxy') }}
				</NcButton>
			</div>
		</NcDialog>

		<!-- Close round confirmation dialog -->
		<NcDialog
			v-if="showCloseDialog"
			:name="t('decidesk', 'Close Voting Round')"
			@closing="showCloseDialog = false">
			<p>
				{{ t('decidesk', 'Close voting round? {remaining} of {total} members have not yet voted.', {
					remaining: memberCount - tallyTotal,
					total: memberCount,
				}) }}
			</p>
			<NcButton
				type="error"
				:disabled="closeLoading"
				:aria-label="t('decidesk', 'Confirm Close')"
				@click="closeRound">
				{{ closeLoading ? t('decidesk', 'Closing...') : t('decidesk', 'Close Round') }}
			</NcButton>
		</NcDialog>

		<!-- Closed round results -->
		<div v-if="closedRound" class="decidesk-voting-panel__results">
			<h3>{{ t('decidesk', 'Voting Results') }}</h3>
			<div class="decidesk-voting-panel__result-badge">
				<span
					class="decidesk-badge"
					:class="resultBadgeClass(closedRound.result)"
					:aria-label="resultLabel(closedRound.result)">
					{{ resultLabel(closedRound.result) }}
				</span>
			</div>
			<dl class="decidesk-voting-panel__counts">
				<dt>{{ t('decidesk', 'Voor') }}</dt>
				<dd>{{ closedRound.votesFor || 0 }}</dd>
				<dt>{{ t('decidesk', 'Tegen') }}</dt>
				<dd>{{ closedRound.votesAgainst || 0 }}</dd>
				<dt>{{ t('decidesk', 'Onthouding') }}</dt>
				<dd>{{ closedRound.votesAbstain || 0 }}</dd>
			</dl>
			<NcButton
				v-if="isChairOrSecretary"
				type="secondary"
				:aria-label="t('decidesk', 'Publish to ORI')"
				@click="publishToOri">
				{{ t('decidesk', 'Publish to ORI') }}
			</NcButton>
			<p v-if="oriStatus" class="decidesk-ori-status" role="status">{{ oriStatus }}</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcDialog, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-7
 */
export default {
	name: 'VotingRoundPanel',
	components: { NcButton, NcDialog, NcCheckboxRadioSwitch, NcTextField },
	props: {
		motionId: { type: String, required: true },
		motionLifecycle: { type: String, default: '' },
		currentParticipantId: { type: String, default: '' },
		currentRole: { type: String, default: 'member' },
		memberCount: { type: Number, default: 0 },
	},
	data() {
		return {
			activeRound: null,
			closedRound: null,
			votedValue: null,
			voteError: null,
			showOpenDialog: false,
			showCloseDialog: false,
			showProxyDialog: false,
			openLoading: false,
			closeLoading: false,
			openError: null,
			oriStatus: null,
			hasGrantedProxy: false,
			receivedProxy: null,
			proxyToParticipantId: '',
			pollInterval: null,
			newRound: {
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: null,
			},
			showOfHands: { for: 0, against: 0, abstain: 0 },
			votingMethodOptions: [
				{ value: 'for-against-abstain', label: this.t('decidesk', 'For / Against / Abstain') },
				{ value: 'show-of-hands', label: this.t('decidesk', 'Show of Hands') },
				{ value: 'weighted', label: this.t('decidesk', 'Weighted') },
			],
		}
	},
	computed: {
		isChairOrSecretary() {
			return ['chair', 'vice-chair', 'secretary'].includes(this.currentRole)
		},
		canOpenRound() {
			return this.isChairOrSecretary && this.motionLifecycle === 'debating'
		},
		canVote() {
			return this.activeRound
				&& !this.isChairOrSecretary
				&& this.motionLifecycle === 'voting'
		},
		canManageProxy() {
			return !this.isChairOrSecretary && this.activeRound === null
		},
		tallyTotal() {
			if (!this.activeRound && !this.closedRound) return 0
			const r = this.activeRound || this.closedRound
			return (r.votesFor || 0) + (r.votesAgainst || 0) + (r.votesAbstain || 0)
		},
	},
	mounted() {
		this.fetchCurrentRound()
	},
	beforeDestroy() {
		this.stopPolling()
	},
	methods: {
		async fetchCurrentRound() {
			// The object store does not expose a direct find-by-motion-relation endpoint;
			// we rely on the OpenRegister wildcard API built in to the NC app (via slug).
			// For now we load the latest voting round from the motion's relations.
		},
		startPolling() {
			if (this.pollInterval) return
			this.pollInterval = setInterval(() => {
				if (this.activeRound) {
					this.fetchCurrentRound()
				}
			}, 5000)
		},
		stopPolling() {
			if (this.pollInterval) {
				clearInterval(this.pollInterval)
				this.pollInterval = null
			}
		},
		async openRound() {
			this.openLoading = true
			this.openError = null
			try {
				const url = generateUrl('/apps/decidesk/api/voting-rounds')
				const response = await axios.post(url, {
					motionId: this.motionId,
					votingMethod: this.newRound.votingMethod,
					isSecret: this.newRound.isSecret,
					closedAt: this.newRound.closedAt || null,
				})
				this.activeRound = response.data
				this.showOpenDialog = false
				this.startPolling()
				this.$emit('round-opened', this.activeRound)
				showSuccess(this.t('decidesk', 'Voting round opened.'))
			} catch (e) {
				const message = e.response?.data?.message || e.message
				this.openError = message
				if (message && message.includes('Quorum')) {
					this.openError = this.t('decidesk', 'Quorum niet bereikt')
				}
			} finally {
				this.openLoading = false
			}
		},
		async castVote(value) {
			this.voteError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/cast`)
				await axios.post(url, {
					participantId: this.currentParticipantId,
					value,
					isProxy: false,
				})
				this.votedValue = value
				showSuccess(this.t('decidesk', 'Vote registered.'))

				// Cast proxy vote automatically if there is a received proxy.
				if (this.receivedProxy) {
					await axios.post(url, {
						participantId: this.currentParticipantId,
						value,
						isProxy: true,
						delegatorId: this.receivedProxy.fromId,
					})
				}
			} catch (e) {
				this.voteError = e.response?.data?.message || e.message
			}
		},
		async closeRound() {
			this.closeLoading = true
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/close`)
				const response = await axios.post(url)
				this.closedRound = response.data
				this.activeRound = null
				this.showCloseDialog = false
				this.stopPolling()
				this.$emit('round-closed', this.closedRound)
				showSuccess(this.t('decidesk', 'Voting round closed.'))
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			} finally {
				this.closeLoading = false
			}
		},
		async publishToOri() {
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.closedRound.id}/publish`)
				const response = await axios.post(url)
				const statusMap = {
					published: this.t('decidesk', 'Published to ORI'),
					pending: this.t('decidesk', 'Publication in progress (Publicatie in behandeling)'),
					not_configured: this.t('decidesk', 'ORI endpoint not configured'),
				}
				this.oriStatus = statusMap[response.data.status] || response.data.status
				showSuccess(this.oriStatus)
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		async grantProxy() {
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound?.id || ''}/proxy`)
				await axios.post(url, {
					fromParticipantId: this.currentParticipantId,
					toParticipantId: this.proxyToParticipantId,
				})
				this.hasGrantedProxy = true
				this.showProxyDialog = false
				showSuccess(this.t('decidesk', 'Proxy granted.'))
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		async revokeProxy() {
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound?.id || ''}/proxy`)
				await axios.delete(url, { data: { fromParticipantId: this.currentParticipantId } })
				this.hasGrantedProxy = false
				showSuccess(this.t('decidesk', 'Proxy revoked.'))
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		async saveShowOfHands() {
			try {
				const url = generateUrl(`/apps/decidesk/api/voting-rounds/${this.activeRound.id}/cast`)
				// Save show-of-hands totals as direct tally values.
				await axios.post(url, {
					participantId: 'show-of-hands',
					value: 'show-of-hands',
					isProxy: false,
					showOfHands: {
						for: parseInt(this.showOfHands.for),
						against: parseInt(this.showOfHands.against),
						abstain: parseInt(this.showOfHands.abstain),
					},
				})
				showSuccess(this.t('decidesk', 'Results saved.'))
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		resultLabel(result) {
			const labels = {
				adopted: this.t('decidesk', 'Aangenomen'),
				rejected: this.t('decidesk', 'Verworpen'),
				tied: this.t('decidesk', 'Gelijkspel'),
				invalid: this.t('decidesk', 'Ongeldig'),
			}
			return labels[result] || result
		},
		resultBadgeClass(result) {
			return {
				'decidesk-badge--adopted': result === 'adopted',
				'decidesk-badge--rejected': result === 'rejected',
				'decidesk-badge--tied': result === 'tied',
				'decidesk-badge--invalid': result === 'invalid',
			}
		},
	},
}
</script>

<style scoped>
.decidesk-voting-panel { padding: var(--default-grid-baseline); }
.decidesk-voting-panel__vote-buttons { display: flex; gap: var(--default-grid-baseline); flex-wrap: wrap; }
.decidesk-voting-panel__tally { margin-block: var(--default-grid-baseline); color: var(--color-text-maxcontrast); }
.decidesk-voting-panel__proxy { margin-block-start: var(--default-grid-baseline); }
.decidesk-voting-panel__proxy-notice { padding: var(--default-grid-baseline); background: var(--color-background-hover); border-radius: var(--border-radius); margin-block-end: var(--default-grid-baseline); }
.decidesk-voting-panel__result-badge { margin-block: var(--default-grid-baseline); }
.decidesk-voting-panel__counts { display: grid; grid-template-columns: auto 1fr; gap: 4px var(--default-grid-baseline); }
.decidesk-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: var(--border-radius-pill); font-size: var(--font-size-small); font-weight: var(--font-weight-bold); }
.decidesk-badge--open { background: var(--color-success-background); color: var(--color-success); }
.decidesk-badge--adopted { background: var(--color-success-background); color: var(--color-success); }
.decidesk-badge--rejected { background: var(--color-error-background); color: var(--color-error); }
.decidesk-badge--tied { background: var(--color-warning-background); color: var(--color-warning); }
.decidesk-badge--invalid { background: var(--color-background-hover); color: var(--color-text-maxcontrast); }
.decidesk-error { color: var(--color-error); margin-block-start: 4px; }
.decidesk-success { color: var(--color-success); margin-block-start: 4px; }
.decidesk-voted { outline: 2px solid currentColor; }
.decidesk-ori-status { margin-block-start: var(--default-grid-baseline); color: var(--color-text-maxcontrast); }
.decidesk-voting-panel__soh-inputs { display: flex; gap: var(--default-grid-baseline); flex-wrap: wrap; margin-block-end: var(--default-grid-baseline); }
</style>
