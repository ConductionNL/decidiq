<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CnDecisionsTab — "Besluitvorming" sidebar tab for the
 `decidesk-decisions` integration leaf (ADR-019 / ADR-022).

 The card a host page shows the decidiq decisions on (dossiq DqZaakBesluiten):
 a title and a line under it, "Prepare decision", and per decision a row
 (initials, title, a meta line, a status pill, "Open in decidiq"), its route
 as a step bar, and a notice when the register says the drafter may take
 it. "Prepare decision" opens an in-tab CnFormDialog that creates a decision
 pre-linked to the host object via the Decision schema's `subjectId`
 back-reference (decidiq-contract-decision-hub, REQ-DCDH-001).

 Generic by construction: the host object identity arrives through the
 registry-supplied props (register / schema / objectId); the tab never
 hard-codes procest. Read-mostly + link-out: deliberation, voting and
 publication stay in decidiq.
-->
<template>
	<section
		class="cn-decisions-tab cn-case-widget-card"
		data-testid="decisions-leaf-tab"
		:aria-labelledby="headingId">
		<div class="cn-decisions-tab__header">
			<div class="cn-decisions-tab__heading">
				<h3 :id="headingId" class="cn-decisions-tab__title">
					{{ titleLabel }}
				</h3>
				<span class="cn-decisions-tab__subtitle">
					{{ subtitleLabel }}
				</span>
			</div>
			<NcButton
				class="cn-decisions-tab__prepare"
				variant="secondary"
				:disabled="!hostObjectId"
				data-testid="decisions-leaf-prepare"
				@click="openCreate">
				<template #icon>
					<Gavel :size="18" />
				</template>
				{{ createProposalLabel }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="cn-decisions-tab__error" role="alert">
			{{ error }}
		</div>

		<p v-else-if="decisions.length === 0" class="cn-decisions-tab__empty">
			{{ emptyLabel }}
		</p>

		<ul v-else class="cn-decisions-tab__list">
			<li
				v-for="decision in decisions"
				:key="rowKey(decision)"
				class="cn-decisions-tab__decision"
				data-testid="decisions-leaf-row">
				<div class="cn-decisions-tab__row">
					<span class="cn-decisions-tab__avatar" aria-hidden="true">{{
						initials(decision)
					}}</span>
					<span class="cn-decisions-tab__text">
						<strong class="cn-decisions-tab__name">{{
							rowTitle(decision)
						}}</strong>
						<span class="cn-decisions-tab__meta">{{
							metaLine(decision)
						}}</span>
					</span>
					<span class="cn-decisions-tab__row-actions">
						<CnStatusBadge
							size="small"
							:variant="lifecycleVariant(decision)"
							:label="lifecycleLabel(decision)" />
						<NcButton
							variant="secondary"
							:href="decisionUrl(decision)"
							target="_blank">
							<template #icon>
								<OpenInNew :size="18" />
							</template>
							{{ openInDecidiqLabel }}
						</NcButton>
					</span>
				</div>

				<ol
					v-if="stepsOf(decision).length > 0"
					class="cn-decisions-tab__steps"
					:aria-label="routeLabel"
					data-testid="decisions-leaf-steps">
					<li
						v-for="step in stepsOf(decision)"
						:key="step.key"
						class="cn-decisions-tab__step"
						:class="`cn-decisions-tab__step--${step.state}`">
						<span
							class="cn-decisions-tab__step-bar"
							aria-hidden="true" />
						<span class="cn-decisions-tab__step-label">{{
							step.label
						}}</span>
						<span v-if="step.line" class="cn-decisions-tab__step-line">{{
							step.line
						}}</span>
					</li>
				</ol>

				<p
					v-if="noticeOf(decision)"
					class="cn-decisions-tab__notice"
					data-testid="decisions-leaf-mandate">
					<ShieldCheckOutline
						:size="18"
						class="cn-decisions-tab__notice-icon" />
					<span>{{ noticeText(noticeOf(decision)) }}</span>
				</p>
			</li>
		</ul>

		<CnFormDialog
			v-if="createOpen"
			:schema="createSchema"
			:dialogTitle="createProposalLabel"
			@close="createOpen = false"
			@confirm="onCreate" />
	</section>
</template>

<script>
import { CnFormDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import {
	decisionAuthority,
	decisionMetaLine,
	drafterName,
	initialsOf,
	listDecisionStages,
	mandateNotice,
	personNames,
	stageSteps,
	userDisplayNames,
} from './decisionCard.js'
import { objId } from './decisionLink.js'
import {
	hostDecisionsMixin,
	isDraft,
	lifecycleLabelOf,
	lifecycleVariantOf,
} from './hostDecisions.js'
import { hostProposalMixin } from './hostProposalCreate.js'

/**
 * CnDecisionsTab — "Besluitvorming" sidebar tab for the
 * `decidesk-decisions` leaf. See the file-level docblock for behaviour
 * and the case-linking mechanism.
 */
export default {
	name: 'CnDecisionsTab',

	components: {
		CnStatusBadge,
		CnFormDialog,
		NcButton,
		NcLoadingIcon,
		Gavel,
		OpenInNew,
		ShieldCheckOutline,
	},

	mixins: [hostDecisionsMixin, hostProposalMixin],

	data() {
		return {
			/** Route stages by decision id. */
			stages: {},
			/** Person names by id, for the stages. */
			people: {},
			/** Authority answers by decision id. */
			authority: {},
			/** Display names by account, for the drafters. */
			userNames: {},
		}
	},

	computed: {
		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — empty-state copy for the leaf surface. */
		emptyLabel() {
			return t('decidiq', 'No decision has been prepared yet.')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route */
		createProposalLabel() {
			return t('decidiq', 'Prepare decision')
		},

		/**
		 * @return {string} The card's title.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		titleLabel() {
			return t('decidiq', 'Decisions')
		},

		/**
		 * @return {string} The line under the title.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		subtitleLabel() {
			return t('decidiq', 'From decision-making in decidiq, shown here')
		},

		/**
		 * @return {string} The step bar's accessible name.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		routeLabel() {
			return t('decidiq', 'Route of the decision')
		},

		/**
		 * @return {string} A unique id for the heading.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		headingId() {
			return `cn-decisions-tab-${this.hostObjectId || 'none'}`
		},
	},

	methods: {
		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — format decision date for display.
		 */
		decisionDate(decision) {
			const raw = decision?.decisionDate ?? decision?.data?.decisionDate ?? ''
			if (!raw) {
				return ''
			}
			const d = new Date(raw)
			return Number.isNaN(d.getTime())
				? ''
				: d.toLocaleDateString(undefined, { dateStyle: 'medium' })
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle label.
		 */
		lifecycleLabel(decision) {
			if (isDraft(decision)) {
				return t('decidiq', 'Draft')
			}
			return lifecycleLabelOf(decision)
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle badge variant.
		 */
		lifecycleVariant(decision) {
			if (isDraft(decision)) {
				return 'default'
			}
			return lifecycleVariantOf(decision)
		},

		/**
		 * Who drafted a decision: its proposer, else its owner by display name.
		 *
		 * @param {object} decision The decision.
		 * @return {string} The name, or ''.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		drafterOf(decision) {
			return drafterName(decision, this.userNames)
		},

		/**
		 * @param {object} decision The decision.
		 * @return {string} The row's initials.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		initials(decision) {
			return initialsOf(this.drafterOf(decision))
		},

		/**
		 * @param {object} decision The decision.
		 * @return {string} The line under the row's title.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		metaLine(decision) {
			const created = decision?.['@self']?.created || ''
			return decisionMetaLine(
				{
					state: this.lifecycleLabel(decision),
					outcome: String(decision?.outcome || ''),
					drafter: this.drafterOf(decision),
					date: created ? this.shortDate(created) : '',
				},
				(drafter, date) =>
					t('decidiq', 'drafted by {drafter} on {date}', {
						drafter,
						date,
					}),
			)
		},

		/**
		 * @param {string} iso An ISO date.
		 * @return {string} The date in short words, or ''.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		shortDate(iso) {
			const d = new Date(iso)
			return Number.isNaN(d.getTime())
				? ''
				: d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
		},

		/**
		 * @param {object} decision The decision.
		 * @return {Array<object>} Its route as steps.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		stepsOf(decision) {
			return stageSteps(
				this.stages[objId(decision)] || [],
				this.people,
				(iso) => this.shortDate(iso),
				t('decidiq', 'not yet'),
			)
		},

		/**
		 * @param {object} decision The decision.
		 * @return {object|null} Its mandate notice, or null.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
		 */
		noticeOf(decision) {
			return mandateNotice(
				this.authority[objId(decision)] || null,
				this.drafterOf(decision),
			)
		},

		/**
		 * @param {{drafter: string, allocation: string, restrictions: string}} notice The notice.
		 * @return {string} The notice in words.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		noticeText(notice) {
			const head = notice.allocation
				? t(
						'decidiq',
						'{drafter} may take this decision: mandate {allocation}.',
						{ drafter: notice.drafter, allocation: notice.allocation },
					)
				: t('decidiq', '{drafter} may take this decision.', {
						drafter: notice.drafter,
					})
			return notice.restrictions ? `${head} ${notice.restrictions}` : head
		},

		/**
		 * Read each decision's route and authority answer, and the people the
		 * routes name. A part that cannot be read is left out of the card.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		async loadDecisionDetails() {
			const ids = this.decisions
				.map((decision) => objId(decision))
				.filter(Boolean)
			const stages = {}
			const authority = {}
			await Promise.all(
				ids.map(async (id) => {
					const [routeStages, answer] = await Promise.all([
						listDecisionStages(id),
						decisionAuthority(id),
					])
					stages[id] = routeStages
					authority[id] = answer
				}),
			)
			this.stages = stages
			this.authority = authority
			const owners = this.decisions
				.filter((decision) => !decision?.proposer)
				.map((decision) => decision?.['@self']?.owner)
			const [people, userNames] = await Promise.all([
				personNames(
					Object.values(stages)
						.flat()
						.map((stage) => stage?.assignedPerson),
				),
				userDisplayNames(owners),
			])
			this.people = people
			this.userNames = userNames
		},
	},
}
</script>

<style scoped>
.cn-decisions-tab {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px 22px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container-large, 12px);
	background: var(--color-main-background);
	overflow-x: hidden;
}

.cn-decisions-tab__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 10px 16px;
}

.cn-decisions-tab__heading {
	display: flex;
	flex: 1 1 260px;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
}

.cn-decisions-tab__title {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
	line-height: 1.2;
}

.cn-decisions-tab__subtitle,
.cn-decisions-tab__empty {
	/* A theme sizes every paragraph through this token with !important. */
	--nldesign-component-paragraph-font-size: 14px;
	margin: 0;
	font-size: 14px;
	line-height: 1.2;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-tab__error {
	color: var(--color-error-text, var(--color-error));
	font-size: 14px;
}

.cn-decisions-tab__list {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

/* The route stands 28px under the row (12px here plus its 16px margin) and
   the notice 12px under the route; the last decision ends on its notice
   (DqZaakBesluiten). */
.cn-decisions-tab__decision {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
}

.cn-decisions-tab__decision:last-child {
	padding-bottom: 0;
}

.cn-decisions-tab__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}

.cn-decisions-tab__avatar {
	display: inline-flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 16px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	font-weight: 700;
}

.cn-decisions-tab__text {
	display: flex;
	flex: 1 1 240px;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.cn-decisions-tab__name {
	font-size: 15px;
	font-weight: 600;
	line-height: 1.2;
}

.cn-decisions-tab__meta {
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-tab__row-actions {
	display: flex;
	flex: none;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.cn-decisions-tab__steps {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 8px;
	margin: 16px 0 0;
	padding: 0;
	list-style: none;
}

/* A route step as the board draws it: a 6px bar, the step and its line on
   the normal line height, 6px apart (DqZaakBesluiten, 49px a step). */
.cn-decisions-tab__step {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
	line-height: normal;
}

.cn-decisions-tab__step-bar {
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-darker);
}

.cn-decisions-tab__step--done .cn-decisions-tab__step-bar {
	background: var(--color-primary-element);
}

.cn-decisions-tab__step--current .cn-decisions-tab__step-bar {
	background: var(--color-error);
}

.cn-decisions-tab__step-label {
	font-size: 14px;
	font-weight: 400;
	color: var(--color-main-text);
}

.cn-decisions-tab__step--done .cn-decisions-tab__step-label {
	font-weight: 600;
}

.cn-decisions-tab__step--current .cn-decisions-tab__step-label {
	font-weight: 700;
}

/* "Prepare decision" is the board's 40px header control. */
.cn-decisions-tab__prepare.button-vue {
	--button-size: var(--cn-board-control-height, 40px);
}

.cn-decisions-tab__step-line {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-tab__notice {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin: 0;
	padding: 10px 14px;
	border-radius: 10px;
	/* The board's light success ground (DqZaakBesluiten), the theme's
	   green-light token as the success badge reads it. */
	background: var(--nl-color-green-light, #e7f3ea);
	color: var(--nl-color-green-text, var(--color-success-text, #1e6b2a));
	font-size: 13px;
}

.cn-decisions-tab__notice-icon {
	flex: none;
}
</style>
