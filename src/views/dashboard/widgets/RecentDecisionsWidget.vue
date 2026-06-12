<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 RecentDecisionsWidget — the latest decisions with outcome + publication
 badges (REQ-003).

 Fetches decisions, sorts by decisionDate descending and caps at 10. Each row
 shows the decision title, an outcome badge (Adopted / Rejected / Undecided —
 outcome enum is [adopted, rejected], null ⇒ Undecided) and a publication
 badge (Internal / Public / Confidential). Clicking a row navigates to the
 decision detail view.

 Badge label keys are emitted as literal strings here so the l10n extractor
 picks them up: 'Adopted', 'Rejected', 'Undecided', 'Internal', 'Public',
 'Confidential'. The widgetLogic badge mappers return those same English keys,
 translated at render via t().
-->
<template>
	<div class="dashboard-list-widget" data-testid="recent-decisions">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('decidesk', 'No decisions yet')"
			data-testid="recent-decisions-empty">
			<template #icon>
				<GavelIcon :size="32" />
			</template>
		</NcEmptyContent>

		<ul v-else class="dashboard-list-widget__list">
			<li v-for="decision in rows"
				:key="decision.id"
				:data-testid="`recent-decision-row-${decision.id}`"
				class="dashboard-list-widget__row"
				@click="openDecision(decision)">
				<span class="dashboard-list-widget__title">{{ decision.title || decision.name }}</span>
				<div class="dashboard-list-widget__aside">
					<span :class="`dashboard-list-widget__badge--${outcome(decision).variant}`"
						class="dashboard-list-widget__badge">
						{{ t('decidesk', outcome(decision).label) }}
					</span>
					<span :class="`dashboard-list-widget__badge--${publication(decision).variant}`"
						class="dashboard-list-widget__badge">
						{{ t('decidesk', publication(decision).label) }}
					</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { recentDecisions, outcomeBadge, publicationBadge } from './widgetLogic.js'
import { getDecisions } from '../../../services/dashboardData.js'

export default {
	name: 'RecentDecisionsWidget',

	components: { NcEmptyContent, NcLoadingIcon, GavelIcon },

	mixins: [dashboardRefreshMixin],

	props: {
		/** Maximum number of decisions to show. */
		limit: {
			type: Number,
			default: 10,
		},
	},

	data() {
		return {
			/** Decisions fetched from OR. */
			decisions: [],
		}
	},

	computed: {
		/**
		 * The most recent decisions, newest first, capped at `limit`.
		 *
		 * @return {Array<object>} Recent decision rows.
		 */
		rows() {
			return recentDecisions(this.decisions, this.limit)
		},
	},

	methods: {
		/**
		 * Fetch decisions. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.decisions = await getDecisions()
			} catch (e) {
				console.error('[decidesk] RecentDecisionsWidget load failed', e)
				this.error = e
				this.decisions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Outcome badge descriptor for a decision.
		 *
		 * @param {object} decision The decision object.
		 * @return {{ label: string, variant: string }} Label key + variant.
		 */
		outcome(decision) {
			return outcomeBadge(decision.outcome)
		},

		/**
		 * Publication badge descriptor for a decision.
		 *
		 * @param {object} decision The decision object.
		 * @return {{ label: string, variant: string }} Label key + variant.
		 */
		publication(decision) {
			return publicationBadge(decision.isPublished)
		},

		/**
		 * Navigate to the decision detail view.
		 *
		 * @param {object} decision The clicked decision.
		 * @return {void}
		 */
		openDecision(decision) {
			this.$router.push({ name: 'DecisionDetail', params: { id: String(decision.id) } })
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

.dashboard-list-widget__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dashboard-list-widget__aside {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

.dashboard-list-widget__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	background: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
}

.dashboard-list-widget__badge--success {
	background: var(--color-success, #2d7b40);
	color: var(--color-primary-text, #fff);
}

.dashboard-list-widget__badge--error {
	background: var(--color-error, #c93030);
	color: var(--color-primary-text, #fff);
}

.dashboard-list-widget__badge--warning {
	background: var(--color-warning, #c28900);
	color: var(--color-primary-text, #fff);
}
</style>
