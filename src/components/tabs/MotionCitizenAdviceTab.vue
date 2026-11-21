<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion page widget: the advisory vote by residents (issue #1418).

 Shows whether residents can give their advice on this motion, lets the
 griffie open and close it, and after closing shows the counts under the
 not-binding caption. The counts live on the motion itself
 (citizenAdviceFor, citizenAdviceAgainst, citizenAdviceAbstain), written by
 CitizenAdviceService when the vote closes, so the council's own voting
 round next to this widget never counts a resident's vote.

 Who may open or close is decided by the server (403 for anyone who is not
 the secretariat, an admin, or the chair or secretary of the motion's
 meeting); the refusal is shown as it comes back.

 @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--citizen-advice"
		data-testid="motion-citizen-advice-tab">
		<p class="decidiq-tab__hint">
			{{ t('decidiq', 'Advisory vote by residents, not binding') }}
		</p>

		<p v-if="loading" class="decidiq-tab__empty">
			{{ t('decidiq', 'Loading…') }}
		</p>

		<template v-else>
			<CnNoteCard
				v-if="!motion.citizenVotingAllowed"
				type="info"
				:title="t('decidiq', 'Residents cannot vote on this motion')">
				{{ t('decidiq', 'Allow citizen voting on the motion first.') }}
			</CnNoteCard>

			<template v-else>
				<p
					class="citizen-advice__status"
					data-testid="citizen-advice-status">
					{{ statusLabel }}
				</p>

				<dl
					v-if="status === 'closed'"
					class="citizen-advice__counts"
					data-testid="citizen-advice-counts">
					<div>
						<dt>{{ t('decidiq', 'For') }}</dt>
						<dd>{{ motion.citizenAdviceFor || 0 }}</dd>
					</div>
					<div>
						<dt>{{ t('decidiq', 'Against') }}</dt>
						<dd>{{ motion.citizenAdviceAgainst || 0 }}</dd>
					</div>
					<div>
						<dt>{{ t('decidiq', 'Abstain') }}</dt>
						<dd>{{ motion.citizenAdviceAbstain || 0 }}</dd>
					</div>
				</dl>

				<NcButton
					v-if="status === 'not-open'"
					variant="primary"
					data-testid="citizen-advice-open"
					:disabled="busy"
					@click="change('open')">
					{{ t('decidiq', 'Open advisory vote') }}
				</NcButton>
				<NcButton
					v-else-if="status === 'open'"
					variant="secondary"
					data-testid="citizen-advice-close"
					:disabled="busy"
					@click="change('close')">
					{{ t('decidiq', 'Close advisory vote') }}
				</NcButton>
			</template>

			<CnNoteCard
				v-if="error"
				type="error"
				:title="t('decidiq', 'The advisory vote could not be changed')">
				{{ error }}
			</CnNoteCard>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MotionCitizenAdviceTab',
	components: { CnNoteCard, NcButton },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			motion: {},
		}
	},

	computed: {
		/**
		 * The motion this widget is mounted on. The route parameter is the
		 * fallback because a manifest custom widget does not receive the
		 * object id (see MotionVotingRoundTab).
		 *
		 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
		 * @return {string} The motion uuid, or '' when unresolvable
		 */
		motionId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
		 * @return {string} not-open, open or closed
		 */
		status() {
			return this.motion.citizenVotingStatus || 'not-open'
		},

		/**
		 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
		 * @return {string} The status in words
		 */
		statusLabel() {
			if (this.status === 'open') {
				return this.t('decidiq', 'Residents can give their advice now.')
			}
			if (this.status === 'closed') {
				return this.t('decidiq', 'The advisory vote has closed.')
			}
			return this.t('decidiq', 'The advisory vote has not been opened.')
		},
	},

	watch: {
		motionId: {
			immediate: true,
			/** @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote */
		async refresh() {
			if (!this.motionId) return
			this.loading = true
			try {
				const motionStore = ensureRelationType('motion')
				this.motion =
					(await motionStore.fetchObject('motion', this.motionId)) || {}
			} catch {
				this.motion = {}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open or close the advisory vote.
		 *
		 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
		 * @param {string} action open or close
		 */
		async change(action) {
			this.busy = true
			this.error = ''
			try {
				const res = await fetch(
					generateUrl(
						`/apps/decidiq/api/motions/${this.motionId}/citizen-advice/${action}`,
					),
					{
						method: 'POST',
						headers: {
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				const body = await res.json()
				if (!res.ok) {
					this.error =
						body?.message
						|| this.t(
							'decidiq',
							'The advisory vote could not be changed',
						)
					return
				}
				this.motion = body?.motion || this.motion
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The advisory vote could not be changed')
			} finally {
				this.busy = false
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

.decidiq-tab__hint,
.decidiq-tab__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.citizen-advice__status {
	margin: 0;
}

.citizen-advice__counts {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 4);
	margin: 0;
}

.citizen-advice__counts dt {
	color: var(--color-text-maxcontrast);
}

.citizen-advice__counts dd {
	font-size: 1.5em;
	font-weight: bold;
	margin: 0;
}
</style>
