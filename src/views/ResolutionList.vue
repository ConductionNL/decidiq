<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — fleet-wide resolutions index (Phase 8.3 secretary view).

 Lists every Resolution across every board with quick links to ResolutionDetail.
 Filtered client-side by status; the API surface lives at
 GET /api/resolutions.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.3
-->
<template>
	<div class="resolution-list" data-testid="resolution-list">
		<header class="resolution-list__header">
			<h2>{{ t('decidesk', 'Resolutions') }}</h2>
			<select v-model="statusFilter"
				class="resolution-list__filter"
				data-testid="resolution-list-status-filter">
				<option value="">
					{{ t('decidesk', 'All statuses') }}
				</option>
				<option v-for="opt in statusOptions" :key="opt" :value="opt">
					{{ opt }}
				</option>
			</select>
		</header>

		<NcLoadingIcon v-if="loading" :size="48" />

		<NcEmptyContent
			v-else-if="filteredResolutions.length === 0"
			:name="t('decidesk', 'No resolutions yet')"
			:description="t('decidesk', 'Resolutions are proposed from a board meeting.')"
			data-testid="resolution-list-empty" />

		<table v-else class="resolution-list__table" data-testid="resolution-list-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidesk', '#') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Title') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Type') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Threshold') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Status') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="r in filteredResolutions"
					:key="r.id"
					:data-testid="`resolution-row-${r.id}`"
					class="resolution-list__row"
					@click="openResolution(r)">
					<td>{{ r.resolutionNumber }}</td>
					<td>{{ r.title }}</td>
					<td>{{ r.type }}</td>
					<td>{{ r.voteThreshold }}</td>
					<td><span class="resolution-list__badge">{{ r.status }}</span></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'ResolutionList',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			resolutions: [],
			statusFilter: '',
			statusOptions: [
				'proposed',
				'under-discussion',
				'adopted',
				'rejected',
				'withdrawn',
				'tabled',
			],
		}
	},

	computed: {
		/**
		 * Client-side filter on status.
		 *
		 * @return {Array<object>} Filtered resolution rows.
		 */
		filteredResolutions() {
			if (!this.statusFilter) {
				return this.resolutions
			}
			return this.resolutions.filter((r) => r.status === this.statusFilter)
		},
	},

	async mounted() {
		await this.fetchResolutions()
	},

	methods: {
		/**
		 * Fetch all resolutions.
		 *
		 * @return {Promise<void>}
		 */
		async fetchResolutions() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/decidesk/api/resolutions'),
					{ headers: { Accept: 'application/json', requesttoken: OC.requestToken } },
				)
				const payload = await response.json()
				this.resolutions = Array.isArray(payload?.resolutions) ? payload.resolutions : (payload?.results || [])
			} catch (e) {
				console.error('[decidesk] ResolutionList fetch failed', e)
				this.resolutions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to a resolution detail page.
		 *
		 * @param {object} r Resolution row
		 * @return {void}
		 */
		openResolution(r) {
			this.$router.push({ name: 'ResolutionDetail', params: { id: String(r.id) } })
		},
	},
}
</script>

<style scoped>
.resolution-list {
	max-width: 1080px;
	margin: 0 auto;
	padding: 16px;
}

.resolution-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.resolution-list__filter {
	padding: 6px 12px;
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background, #fff);
}

.resolution-list__table {
	width: 100%;
	border-collapse: collapse;
}

.resolution-list__table th,
.resolution-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	text-align: left;
}

.resolution-list__row {
	cursor: pointer;
}

.resolution-list__row:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.resolution-list__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-primary-light, #eaf3fb);
	color: var(--color-primary-text-dark, #1a4a72);
	font-size: 0.85em;
}
</style>
