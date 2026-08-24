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
			:name="t('decidiq', 'No decisions yet')"
			data-testid="recent-decisions-empty">
			<template #icon>
				<GavelIcon :size="32" />
			</template>
		</NcEmptyContent>

		<ul v-else class="dashboard-list-widget__list">
			<!--
				The row navigates on click, so it is a control and must be
				operable from the keyboard (WCAG 2.1.1). Without tabindex it
				could not be reached at all, and without the keydown handlers
				reaching it would not have helped. `.prevent` on space stops the
				page scrolling instead of activating the row.
			-->
			<li
				v-for="decision in rows"
				:key="decision.id"
				:data-testid="`recent-decision-row-${decision.id}`"
				class="dashboard-list-widget__row"
				role="button"
				tabindex="0"
				@click="openDecision(decision)"
				@keydown.enter.prevent="openDecision(decision)"
				@keydown.space.prevent="openDecision(decision)">
				<span class="dashboard-list-widget__title">{{
					decision.title || decision.name
				}}</span>
				<div class="dashboard-list-widget__aside">
					<span
						:class="`dashboard-list-widget__badge--${outcome(decision).variant}`"
						class="dashboard-list-widget__badge">
						{{ t('decidiq', outcome(decision).label) }}
					</span>
					<span
						:class="`dashboard-list-widget__badge--${publication(decision).variant}`"
						class="dashboard-list-widget__badge">
						{{ t('decidiq', publication(decision).label) }}
					</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import { getDecisions } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { outcomeBadge, publicationBadge, recentDecisions } from './widgetLogic.js'

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
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-kpi-cards
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.decisions = await getDecisions()
			} catch (e) {
				console.error('[decidiq] RecentDecisionsWidget load failed', e)
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
			this.$router.push({
				name: 'DecisionDetail',
				params: { id: String(decision.id) },
			})
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

/* The row is keyboard-focusable, so its focus must be VISIBLE (WCAG 2.4.7).
   Without this the row could be tabbed to but not seen, which is worse than
   not being reachable at all. */
.dashboard-list-widget__row:focus-visible {
	background: var(--color-background-hover, #f5f5f5);
	outline: 2px solid var(--color-primary-element, #0082c9);
	outline-offset: -2px;
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
