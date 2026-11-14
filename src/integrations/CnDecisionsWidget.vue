<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CnDecisionsWidget — "Besluitvorming" detail-page widget for the
 `decidesk-decisions` integration leaf (ADR-019 / ADR-022).

 Given a host object's identity ({ register, schema, objectId }) supplied
 by the OpenRegister integration registry, this widget lists the decidiq
 decisions linked to that object — proposals, advice and final decisions —
 grouped by kind. It is generic: the host can be a procest case, an
 opencatalogi catalog, or any OR object. The link is the decidiq Decision
 schema's `subjectId` back-reference (decidiq-contract-decision-hub,
 REQ-DCDH-001); this widget fetches decisions where `subjectId == objectId`.

 Read-mostly + link-out: the full decision workflow (deliberation, voting,
 publication) lives in decidiq, so each row deep-links to decidiq's
 DecisionDetail page and a "Create proposal for this case" action opens the
 in-widget CnFormDialog pre-linked to the host object via subjectId.

 Surface-aware (AD-19): on dashboard surfaces it renders a compact count
 headline; on the detail-page surface a grouped clickable list.

 All UI strings pass through t('decidiq', …) (ADR-007); styling uses
 Nextcloud CSS variables only so the nldesign overrides apply (ADR-010).
-->
<template>
	<CnDetailCard
		v-if="!summarised"
		:title="cardTitle"
		:icon="cardIcon"
		:collapsible="collapsible">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="cn-decisions-widget__error" role="alert">
			{{ error }}
		</div>

		<template v-else>
			<!-- Dashboard surfaces: count headline only. -->
			<div v-if="isDashboardSurface" class="cn-decisions-widget__headline">
				<strong>{{ countLabel }}</strong>
				<a :href="appUrl" class="cn-decisions-widget__open-app">{{
					openInDecidiqLabel
				}}</a>
			</div>

			<!-- Detail-page (and fallback) surface: grouped list. -->
			<template v-else>
				<div
					v-if="decisions.length === 0 && wording.emptyTitle"
					class="cn-decisions-widget__empty-panel">
					<span class="cn-decisions-widget__empty-icon" aria-hidden="true">
						<Gavel :size="32" />
					</span>
					<h3 class="cn-decisions-widget__empty-title">
						{{ wording.emptyTitle }}
					</h3>
					<p
						v-if="wording.emptyText"
						class="cn-decisions-widget__empty-text">
						{{ wording.emptyText }}
					</p>
					<div class="cn-decisions-widget__empty-actions">
						<NcButton
							variant="primary"
							:disabled="!objectId"
							@click="openCreate">
							<template #icon>
								<Plus :size="18" />
							</template>
							{{ createProposalLabel }}
						</NcButton>
						<a
							:href="appUrl"
							target="_blank"
							rel="noopener"
							class="cn-decisions-widget__empty-link">
							<OpenInNew :size="18" />
							{{ openInDecidiqLabel }}
						</a>
					</div>
				</div>
				<div
					v-else-if="decisions.length === 0"
					class="cn-decisions-widget__empty">
					{{ emptyLabel }}
				</div>
				<div
					v-for="group in nonEmptyGroups"
					v-else
					:key="group.key"
					class="cn-decisions-widget__group">
					<h4 class="cn-decisions-widget__group-title">
						{{ group.label }}
						<span class="cn-decisions-widget__group-count"
							>({{ group.items.length }})</span
						>
					</h4>
					<ul class="cn-decisions-widget__list">
						<li
							v-for="decision in group.items"
							:key="rowKey(decision)"
							class="cn-decisions-widget__row">
							<a
								:href="decisionUrl(decision)"
								class="cn-decisions-widget__title"
								:title="rowTitle(decision)"
								>{{ rowTitle(decision) }}</a
							>
							<CnStatusBadge
								size="small"
								:variant="lifecycleVariant(decision)"
								:label="lifecycleLabel(decision)" />
						</li>
					</ul>
				</div>

				<div
					v-if="!(decisions.length === 0 && wording.emptyTitle)"
					class="cn-decisions-widget__actions">
					<NcButton
						variant="primary"
						:disabled="!objectId"
						@click="openCreate">
						<template #icon>
							<Plus :size="18" />
						</template>
						{{ createProposalLabel }}
					</NcButton>
					<NcButton variant="tertiary" @click="openApp">
						<template #icon>
							<OpenInNew :size="18" />
						</template>
						{{ openInDecidiqLabel }}
					</NcButton>
				</div>
			</template>
		</template>

		<CnFormDialog
			v-if="createOpen"
			:schema="createSchema"
			:dialogTitle="createProposalLabel"
			@close="createOpen = false"
			@confirm="onCreate" />
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnFormDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { decisionBucket } from './decisionLink.js'
import { hostDecisionsMixin } from './hostDecisions.js'
import { hostProposalMixin } from './hostProposalCreate.js'
import { hostWording } from './hostWording.js'
import { leafReportMixin } from './leafReport.js'

const DASHBOARD_SURFACES = ['user-dashboard', 'app-dashboard']

/**
 * CnDecisionsWidget — surface-aware "Besluitvorming" widget for the
 * `decidesk-decisions` integration leaf. See the file-level docblock for
 * surface-by-surface behaviour and the case-linking mechanism.
 */
export default {
	name: 'CnDecisionsWidget',

	components: {
		CnDetailCard,
		CnStatusBadge,
		CnFormDialog,
		Gavel,
		NcButton,
		NcLoadingIcon,
		OpenInNew,
		Plus,
	},

	mixins: [leafReportMixin, hostDecisionsMixin, hostProposalMixin],

	props: {
		/** Rendering surface (AD-19). */
		surface: { type: String, default: 'detail-page' },
		/** Whether the card body is collapsible. */
		collapsible: { type: Boolean, default: true },
		/**
		 * The card title the host gives (CnDetailWidgetHost passes the widget's
		 * translated `title`). A page that mounts this leaf twice, such as a
		 * ticket that lists its own decisions and those on the case it became,
		 * can so tell the two cards apart. Empty keeps "Besluitvorming".
		 */
		title: { type: String, default: '' },
		/**
		 * The empty state's title in the host's words, already translated
		 * (nextcloud-vue `labels`). Given, an empty list draws the panel.
		 */
		emptyTitle: { type: String, default: '' },
		/** The host's line under the empty state's title, already translated. */
		emptyText: { type: String, default: '' },
		/** The create-proposal button in the host's words, already translated. */
		createLabel: { type: String, default: '' },
	},

	computed: {
		/**
		 * What this leaf reports to a "linked" card.
		 *
		 * @return {object} The report.
		 * @spec openspec/specs/leaves-linked-card/spec.md#requirement-the-decision-leaves-report-a-summary-to-the-linked-card
		 */
		leafReport() {
			return {
				count: this.decisions.length,
				emptyLabel: this.emptyLabel,
				noun: t('decidiq', 'decisions'),
				rows: this.decisions.map((decision) => ({
					label: this.rowTitle(decision),
					href: this.decisionUrl(decision),
					icon: 'Gavel',
				})),

				actions: [
					{
						label: this.openInDecidiqLabel,
						run: () => window.location.assign(this.appUrl),
					},
				],
			}
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — Besluitvorming widget title. */
		cardTitle() {
			return this.title || t('decidiq', 'Besluitvorming')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — Besluitvorming widget icon. */
		cardIcon() {
			return Gavel
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — adapt widget layout to the host surface. */
		isDashboardSurface() {
			return DASHBOARD_SURFACES.includes(String(this.surface).split(':')[0])
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — empty-state copy for the leaf surface. */
		emptyLabel() {
			return t('decidiq', 'No decision-making linked to this object yet.')
		},

		/**
		 * The host's words over the leaf's own.
		 *
		 * @return {object} emptyTitle, emptyText and createLabel.
		 * @spec openspec/changes/decisions-leaf-speaks-the-hosts-words/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisions-leaf-speaks-the-hosts-words
		 */
		wording() {
			return hostWording(
				{
					emptyTitle: this.emptyTitle,
					emptyText: this.emptyText,
					createLabel: this.createLabel,
				},
				{
					emptyTitle: '',
					emptyText: '',
					createLabel: t('decidiq', 'Create proposal for this object'),
				},
			)
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal action label. */
		createProposalLabel() {
			return this.wording.createLabel
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — linked-decision count label. */
		countLabel() {
			const total = this.decisions.length
			return this.n(
				'decidiq',
				'{count} decision',
				'{count} decisions',
				total,
				{ count: total },
			)
		},

		/**
		 * Grouped buckets, in presentation order: Voorstellen, Adviezen, Besluiten.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — group host decisions by kind.
		 */
		groups() {
			const buckets = { proposals: [], advice: [], decisions: [] }
			for (const decision of this.decisions) {
				buckets[decisionBucket(decision)].push(decision)
			}
			return [
				{
					key: 'proposals',
					label: t('decidiq', 'Proposals'),
					items: buckets.proposals,
				},
				{
					key: 'advice',
					label: t('decidiq', 'Advice'),
					items: buckets.advice,
				},
				{
					key: 'decisions',
					label: t('decidiq', 'Decisions'),
					items: buckets.decisions,
				},
			]
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — render only populated decision groups. */
		nonEmptyGroups() {
			return this.groups.filter((g) => g.items.length > 0)
		},
	},

	methods: {
		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 open decidiq decisions app. */
		openApp() {
			if (typeof window !== 'undefined') {
				window.open(this.appUrl, '_blank', 'noopener')
			}
		},
	},
}
</script>

<style scoped>
.cn-decisions-widget__error {
	color: var(--color-error);
	font-size: 0.9em;
	padding: 8px 0;
}

.cn-decisions-widget__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	padding: 8px 0;
}

.cn-decisions-widget__empty-panel {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px 24px 44px;
	text-align: center;
	border: 1px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-container-large, 12px);
}

.cn-decisions-widget__empty-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 64px;
	height: 64px;
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.cn-decisions-widget__empty-title {
	margin: 4px 0 0;
	font-size: 18px;
	font-weight: 700;
}

.cn-decisions-widget__empty-text {
	max-width: 460px;
	margin: 0;
	font-size: 15px;
	line-height: 1.6;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-widget__empty-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 10px;
	margin-top: 8px;
}

.cn-decisions-widget__empty-link {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	height: 40px;
	padding: 0 6px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-primary-element);
}

.cn-decisions-widget__headline {
	display: flex;
	align-items: baseline;
	gap: 10px;
	font-size: 1.1em;
}

.cn-decisions-widget__open-app,
.cn-decisions-widget__title {
	color: var(--color-primary-element);
	text-decoration: none;
}

.cn-decisions-widget__open-app:hover,
.cn-decisions-widget__title:hover {
	text-decoration: underline;
}

.cn-decisions-widget__group {
	margin-bottom: 10px;
}

.cn-decisions-widget__group-title {
	margin: 6px 0 4px;
	font-size: 0.85em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-widget__group-count {
	font-weight: normal;
}

.cn-decisions-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.cn-decisions-widget__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.cn-decisions-widget__row:last-child {
	border-bottom: none;
}

.cn-decisions-widget__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 13px;
}

.cn-decisions-widget__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 10px;
}
</style>
