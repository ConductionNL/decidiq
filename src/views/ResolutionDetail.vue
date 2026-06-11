<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — resolution detail (Phase 8.1 portal view + 8.3 secretary).

 Renders a resolution with its full text + a live vote tally (per-enum
 counts) + signature status panel. Chairs see open-vote / conclude
 actions; the running tally streams via the BoardVoteController
 /tally endpoint.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.1
 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.3
-->
<template>
	<div class="resolution-detail" data-testid="resolution-detail">
		<NcLoadingIcon v-if="loading" :size="48" />

		<template v-else-if="resolution">
			<header class="resolution-detail__header">
				<NcButton
					type="tertiary"
					data-testid="resolution-detail-back"
					:aria-label="t('decidesk', 'Back to resolutions')"
					@click="$router.push({ name: 'ResolutionList' })">
					← {{ t('decidesk', 'Resolutions') }}
				</NcButton>
				<h2>{{ resolution.resolutionNumber }} — {{ resolution.title }}</h2>
				<dl class="resolution-detail__meta">
					<div><dt>{{ t('decidesk', 'Status') }}</dt><dd>{{ resolution.status }}</dd></div>
					<div><dt>{{ t('decidesk', 'Type') }}</dt><dd>{{ resolution.type }}</dd></div>
					<div><dt>{{ t('decidesk', 'Vote type') }}</dt><dd>{{ resolution.voteType }}</dd></div>
					<div><dt>{{ t('decidesk', 'Threshold') }}</dt><dd>{{ resolution.voteThreshold }}</dd></div>
				</dl>
			</header>

			<section class="resolution-detail__body" data-testid="resolution-detail-body">
				<h3>{{ t('decidesk', 'Resolution') }}</h3>
				<div class="resolution-detail__text">
					{{ resolution.fullText }}
				</div>

				<h3>{{ t('decidesk', 'Background') }}</h3>
				<div class="resolution-detail__text">
					{{ resolution.background }}
				</div>
			</section>

			<section class="resolution-detail__tally" data-testid="resolution-detail-tally">
				<h3>{{ t('decidesk', 'Vote tally') }}</h3>
				<div class="resolution-detail__tally-grid">
					<div v-for="opt in tallyOptions"
						:key="opt.key"
						class="resolution-detail__tally-cell"
						:data-testid="`tally-${opt.key}`">
						<span class="resolution-detail__tally-label">{{ opt.label }}</span>
						<span class="resolution-detail__tally-count">{{ tallyCount(opt.key) }}</span>
					</div>
				</div>
				<p class="resolution-detail__tally-summary" data-testid="resolution-detail-tally-summary">
					{{ t('decidesk', 'Total votes cast: {n}', { n: totalVotes }) }}
				</p>
			</section>

			<section class="resolution-detail__signatures" data-testid="resolution-detail-signatures">
				<h3>{{ t('decidesk', 'Signatures') }}</h3>
				<p v-if="signatureStatus.signed" data-testid="resolution-detail-signed-status">
					{{ t('decidesk', '{level} signed at {when}', { level: signatureStatus.level, when: signatureStatus.signedAt }) }}
				</p>
				<p v-else data-testid="resolution-detail-unsigned-status">
					{{ t('decidesk', 'No signatures collected yet.') }}
				</p>
			</section>

			<section v-if="isChair" class="resolution-detail__actions" data-testid="resolution-detail-actions">
				<NcButton
					v-if="resolution.status === 'proposed'"
					type="primary"
					data-testid="resolution-detail-open-vote"
					:loading="actionInProgress"
					@click="openVote">
					{{ t('decidesk', 'Open vote') }}
				</NcButton>
				<NcButton
					v-if="resolution.status === 'under-discussion'"
					type="primary"
					data-testid="resolution-detail-conclude"
					:loading="actionInProgress"
					@click="conclude">
					{{ t('decidesk', 'Conclude vote') }}
				</NcButton>
			</section>
		</template>

		<NcEmptyContent
			v-else
			:name="t('decidesk', 'Resolution not found')"
			:description="t('decidesk', 'The requested resolution could not be loaded.')" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'ResolutionDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		id: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			resolution: null,
			tally: {},
			signatureStatus: { signed: false, level: '', signedAt: '' },
			actionInProgress: false,
			tallyOptions: [
				{ key: 'in-favor', label: 'In favor' },
				{ key: 'against', label: 'Against' },
				{ key: 'abstain', label: 'Abstain' },
				{ key: 'absent', label: 'Absent' },
				{ key: 'recused-due-to-conflict', label: 'Recused' },
			],
		}
	},

	computed: {
		/**
		 * Total tally count across every enum option.
		 *
		 * @return {number} Sum of every vote option's count.
		 */
		totalVotes() {
			return this.tallyOptions.reduce((sum, opt) => sum + this.tallyCount(opt.key), 0)
		},

		/**
		 * Whether the current user has chair/admin privileges. The actual
		 * authorization runs server-side; this is a UI affordance only.
		 *
		 * @return {boolean} True when the current user can see chair actions.
		 */
		isChair() {
			const user = getCurrentUser()
			return Boolean(user && (user.isAdmin || user.uid))
		},
	},

	async mounted() {
		await this.fetchAll()
	},

	methods: {
		/**
		 * Fetch the resolution + tally + signature status in parallel.
		 *
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const [resolutionResp, tallyResp, signResp] = await Promise.all([
					fetch(generateUrl(`/apps/decidesk/api/resolutions/${this.id}`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/resolutions/${this.id}/tally`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/resolutions/${this.id}/signature-status`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
				])
				const resolutionData = await resolutionResp.json()
				this.resolution = resolutionData?.resolution || resolutionData?.result || null
				const tallyData = await tallyResp.json()
				this.tally = (tallyData?.tally && typeof tallyData.tally === 'object') ? tallyData.tally : (tallyData?.counts || {})
				const signData = await signResp.json()
				if (signData?.status) {
					this.signatureStatus = {
						signed: Boolean(signData.status.signed),
						level: String(signData.status.level || ''),
						signedAt: String(signData.status.signedAt || ''),
					}
				}
			} catch (e) {
				console.error('[decidesk] ResolutionDetail fetch failed', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Look up the count for a single vote enum option.
		 *
		 * @param {string} key Vote enum value
		 * @return {number} Count from the tally map.
		 */
		tallyCount(key) {
			const v = this.tally[key]
			return typeof v === 'number' ? v : 0
		},

		/**
		 * POST open-vote — transitions the resolution to under-discussion.
		 *
		 * @return {Promise<void>}
		 */
		async openVote() {
			this.actionInProgress = true
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/resolutions/${this.id}/open-vote`),
					{
						method: 'POST',
						headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					},
				)
				const payload = await response.json()
				if (payload?.resolution) {
					this.resolution = payload.resolution
				}
			} catch (e) {
				console.error('[decidesk] open-vote failed', e)
			} finally {
				this.actionInProgress = false
			}
		},

		/**
		 * POST conclude — tally + threshold-check + transition.
		 *
		 * @return {Promise<void>}
		 */
		async conclude() {
			this.actionInProgress = true
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/resolutions/${this.id}/conclude`),
					{
						method: 'POST',
						headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					},
				)
				const payload = await response.json()
				if (payload?.resolution) {
					this.resolution = payload.resolution
				}
				await this.fetchAll()
			} catch (e) {
				console.error('[decidesk] conclude failed', e)
			} finally {
				this.actionInProgress = false
			}
		},
	},
}
</script>

<style scoped>
.resolution-detail {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
}

.resolution-detail__meta {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
	margin: 12px 0;
}

.resolution-detail__meta dt {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #595959);
}

.resolution-detail__meta dd {
	font-weight: 500;
	margin: 0;
}

.resolution-detail__text {
	white-space: pre-wrap;
	margin: 8px 0 16px;
}

.resolution-detail__tally-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
	margin: 12px 0;
}

.resolution-detail__tally-cell {
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	padding: 12px;
	background: var(--color-main-background, #fff);
	display: flex;
	flex-direction: column;
	align-items: center;
}

.resolution-detail__tally-label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast, #595959);
}

.resolution-detail__tally-count {
	font-size: 1.5em;
	font-weight: 600;
}

.resolution-detail__actions {
	display: flex;
	gap: 8px;
	margin-top: 24px;
}
</style>
