<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 MyActionItemsWidget — action items assigned to the current user that are open
 or in-progress (REQ-002).

 Fetches action items pre-scoped server-side by assignee = current user uid and
 taskStatus in [open, in-progress], then sorts by dueDate ascending. Past-due
 items are visually distinguished as overdue. Clicking a row navigates to the
 action-item detail view; an empty state is shown when nothing is assigned.
-->
<template>
	<div class="dashboard-list-widget" data-testid="my-action-items">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('decidesk', 'No action items assigned to you')"
			data-testid="my-action-items-empty">
			<template #icon>
				<ClipboardCheckOutline :size="32" />
			</template>
		</NcEmptyContent>

		<ul v-else class="dashboard-list-widget__list">
			<li v-for="item in rows"
				:key="item.id"
				:class="{ 'dashboard-list-widget__row--overdue': item._overdue }"
				:data-testid="`my-action-item-row-${item.id}`"
				class="dashboard-list-widget__row"
				@click="openItem(item)">
				<div class="dashboard-list-widget__main">
					<span class="dashboard-list-widget__title">{{ item.title || item.name }}</span>
					<span class="dashboard-list-widget__meta">{{ formatDate(item.dueDate) }}</span>
				</div>
				<div class="dashboard-list-widget__aside">
					<span v-if="item._overdue"
						class="dashboard-list-widget__badge dashboard-list-widget__badge--overdue">
						{{ t('decidesk', 'Overdue') }}
					</span>
					<span class="dashboard-list-widget__badge">{{ item.taskStatus }}</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { sortByDueDate, isOverdue } from './widgetLogic.js'
import { getActionItems } from '../../../services/dashboardData.js'

export default {
	name: 'MyActionItemsWidget',

	components: { NcEmptyContent, NcLoadingIcon, ClipboardCheckOutline },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			/** The current user's open / in-progress action items. */
			items: [],
		}
	},

	computed: {
		/**
		 * Action items sorted by dueDate ascending, each tagged with an
		 * `_overdue` flag.
		 *
		 * @return {Array<object>} Decorated, sorted action-item rows.
		 */
		rows() {
			const now = Date.now()
			return sortByDueDate(this.items).map((i) => ({
				...i,
				_overdue: isOverdue(i, now),
			}))
		},
	},

	methods: {
		/**
		 * Fetch the current user's open / in-progress action items. Called on
		 * mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const uid = getCurrentUser()?.uid
				if (!uid) {
					this.items = []
					return
				}
				this.items = await getActionItems({
					assignee: uid,
					taskStatus: ['open', 'in-progress'],
				})
			} catch (e) {
				console.error('[decidesk] MyActionItemsWidget load failed', e)
				this.error = e
				this.items = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format an ISO date for display.
		 *
		 * @param {string} value ISO date string.
		 * @return {string} Localised date, or the raw value on failure.
		 */
		formatDate(value) {
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? (value || '') : d.toLocaleDateString()
		},

		/**
		 * Navigate to the action-item detail view.
		 *
		 * @param {object} item The clicked action item.
		 * @return {void}
		 */
		openItem(item) {
			this.$router.push({ name: 'ActionItemDetail', params: { id: String(item.id) } })
		},
	},
}
</script>

<style scoped>
.dashboard-list-widget {
	display: flex;
	flex-direction: column;
	min-height: 0;
}

.dashboard-list-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.dashboard-list-widget__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	cursor: pointer;
}

.dashboard-list-widget__row:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.dashboard-list-widget__row--overdue {
	border-inline-start: 3px solid var(--color-error, #c93030);
}

.dashboard-list-widget__main {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.dashboard-list-widget__aside {
	display: flex;
	align-items: center;
	gap: 8px;
}

.dashboard-list-widget__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dashboard-list-widget__meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
}

.dashboard-list-widget__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	background: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
}

.dashboard-list-widget__badge--overdue {
	background: var(--color-error, #c93030);
	color: var(--color-primary-text, #fff);
}
</style>
