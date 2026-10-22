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
	<section class="cn-decisions-tab" data-testid="decisions-leaf-tab" :aria-labelledby="headingId">
		<div class="cn-decisions-tab__header">
			<div class="cn-decisions-tab__heading">
				<h3 :id="headingId" class="cn-decisions-tab__title">
					{{ titleLabel }}
				</h3>
				<p class="cn-decisions-tab__subtitle">
					{{ subtitleLabel }}
				</p>
			</div>
			<NcButton
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
					<span class="cn-decisions-tab__avatar" aria-hidden="true">{{ initials(decision) }}</span>
					<span class="cn-decisions-tab__text">
						<strong class="cn-decisions-tab__name">{{ rowTitle(decision) }}</strong>
						<span class="cn-decisions-tab__meta">{{ metaLine(decision) }}</span>
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
						<span class="cn-decisions-tab__step-bar" aria-hidden="true" />
						<span class="cn-decisions-tab__step-label">{{ step.label }}</span>
						<span v-if="step.line" class="cn-decisions-tab__step-line">{{ step.line }}</span>
					</li>
				</ol>

				<p
					v-if="noticeOf(decision)"
					class="cn-decisions-tab__notice"
					data-testid="decisions-leaf-mandate">
					<ShieldCheckOutline :size="18" class="cn-decisions-tab__notice-icon" />
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
import { generateUrl } from '@nextcloud/router'
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
import {
	createHostDecision,
	hostProposalPayload,
	isProposal,
	listDecisionTypes,
	listHostDecisions,
	objId,
	proposalFormSchema,
} from './decisionLink.js'

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

	props: {
		/** Stable integration id (forwarded from the registry — always `'decidesk-decisions'`). */
		integrationId: { type: String, default: 'decidesk-decisions' },
		/** OpenRegister register id of the HOST object (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the HOST object (slug or uuid). */
		schema: { type: String, default: '' },
		/** UUID of the HOST object the decisions are linked to. */
		objectId: { type: [String, Number], default: '' },
		/** Whole integration context — fallback when discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
		/** Human label for the host object (stored as subjectLabel on created decisions). */
		objectLabel: { type: String, default: '' },
	},

	data() {
		return {
			decisions: [],
			decisionTypes: null,
			loading: false,
			creating: false,
			error: '',
			createOpen: false,
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
		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostObjectId() {
			return String(this.objectId || this.integrationContext.objectId || '')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostRegister() {
			return this.register || this.integrationContext.register || ''
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostSchema() {
			return this.schema || this.integrationContext.schema || ''
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		appUrl() {
			return generateUrl('/apps/decidiq/decisions')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — empty-state copy for the leaf surface. */
		emptyLabel() {
			return t('decidiq', 'No decision has been prepared yet.')
		},

		/** @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route */
		createProposalLabel() {
			return t('decidiq', 'Prepare decision')
		},

		/**
		 * @return {string} The card's title.
		 *
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		titleLabel() {
			return t('decidiq', 'Decisions')
		},

		/**
		 * @return {string} The line under the title.
		 *
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		subtitleLabel() {
			return t('decidiq', 'From decision-making in decidiq, shown here')
		},

		/**
		 * @return {string} The step bar's accessible name.
		 *
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		routeLabel() {
			return t('decidiq', 'Route of the decision')
		},

		/**
		 * @return {string} A unique id for the heading.
		 *
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		headingId() {
			return `cn-decisions-tab-${this.hostObjectId || 'none'}`
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		openInDecidiqLabel() {
			return t('decidiq', 'Open in decidiq')
		},

		/**
		 * The picker offers the REGISTRY's vocabulary, not a hardcoded list:
		 * a type an administrator adds appears here without a release.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
		 */
		createSchema() {
			return proposalFormSchema(this.decisionTypes)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.refresh()
			},
		},

		integrationContext: {
			handler() {
				this.refresh()
			},
		},
	},

	created() {
		this.loadDecisionTypes()
	},

	methods: {
		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — stable list-row key.
		 */
		rowKey(decision) {
			return objId(decision)
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — list-row title fallback.
		 */
		rowTitle(decision) {
			return (
				String(decision?.title ?? decision?.data?.title ?? '').trim()
				|| t('decidiq', 'Untitled decision')
			)
		},

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
			if (String(decision?.lifecycle ?? decision?.data?.lifecycle ?? '') === 'draft') {
				return t('decidiq', 'Draft')
			}
			if (isProposal(decision)) {
				return t('decidiq', 'Proposal')
			}
			const lifecycle = String(
				decision?.lifecycle ?? decision?.data?.lifecycle ?? 'decided',
			)
			switch (lifecycle) {
				case 'decided':
					return t('decidiq', 'Decided')
				case 'enacted':
					return t('decidiq', 'Enacted')
				case 'archived':
					return t('decidiq', 'Archived')
				case 'withdrawn':
					return t('decidiq', 'Withdrawn')
				default:
					return lifecycle
			}
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle badge variant.
		 */
		lifecycleVariant(decision) {
			if (isProposal(decision)) {
				return 'info'
			}
			const lifecycle = String(
				decision?.lifecycle ?? decision?.data?.lifecycle ?? '',
			)
			switch (lifecycle) {
				case 'decided':
				case 'enacted':
					return 'success'
				case 'withdrawn':
					return 'error'
				default:
					return 'default'
			}
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 deep-link to a decision.
		 */
		decisionUrl(decision) {
			const id = objId(decision)
			return id
				? generateUrl(`/apps/decidiq/decisions/${encodeURIComponent(id)}`)
				: this.appUrl
		},

		/**
		 * Who drafted a decision: its proposer, else its owner by display name.
		 *
		 * @param {object} decision The decision.
		 * @return {string} The name, or ''.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		drafterOf(decision) {
			return drafterName(decision, this.userNames)
		},

		/**
		 * @param {object} decision The decision.
		 * @return {string} The row's initials.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		initials(decision) {
			return initialsOf(this.drafterOf(decision))
		},

		/**
		 * @param {object} decision The decision.
		 * @return {string} The line under the row's title.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
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
				(drafter, date) => t('decidiq', 'drafted by {drafter} on {date}', { drafter, date }),
			)
		},

		/**
		 * @param {string} iso An ISO date.
		 * @return {string} The date in short words, or ''.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		shortDate(iso) {
			const d = new Date(iso)
			return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
		},

		/**
		 * @param {object} decision The decision.
		 * @return {Array<object>} Its route as steps.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		stepsOf(decision) {
			return stageSteps(this.stages[objId(decision)] || [], this.people, (iso) => this.shortDate(iso), t('decidiq', 'not yet'))
		},

		/**
		 * @param {object} decision The decision.
		 * @return {object|null} Its mandate notice, or null.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
		 */
		noticeOf(decision) {
			return mandateNotice(this.authority[objId(decision)] || null, this.drafterOf(decision))
		},

		/**
		 * @param {{drafter: string, allocation: string, restrictions: string}} notice The notice.
		 * @return {string} The notice in words.
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		noticeText(notice) {
			const head = notice.allocation
				? t('decidiq', '{drafter} may take this decision: mandate {allocation}.', { drafter: notice.drafter, allocation: notice.allocation })
				: t('decidiq', '{drafter} may take this decision.', { drafter: notice.drafter })
			return notice.restrictions ? `${head} ${notice.restrictions}` : head
		},

		/**
		 * Read each decision's route and authority answer, and the people the
		 * routes name. A part that cannot be read is left out of the card.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
		 */
		async loadRoutes() {
			const ids = this.decisions.map((decision) => objId(decision)).filter(Boolean)
			const stages = {}
			const authority = {}
			await Promise.all(ids.map(async (id) => {
				const [routeStages, answer] = await Promise.all([listDecisionStages(id), decisionAuthority(id)])
				stages[id] = routeStages
				authority[id] = answer
			}))
			this.stages = stages
			this.authority = authority
			const owners = this.decisions.filter((decision) => !decision?.proposer).map((decision) => decision?.['@self']?.owner)
			const [people, userNames] = await Promise.all([
				personNames(Object.values(stages).flat().map((stage) => stage?.assignedPerson)),
				userDisplayNames(owners),
			])
			this.people = people
			this.userNames = userNames
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 open create-proposal dialog. */
		openCreate() {
			if (this.hostObjectId) {
				this.createOpen = true
			}
		},

		/**
		 * Load the decisionType vocabulary from the registry endpoint.
		 *
		 * Fire-and-forget from created(): until it answers, the picker offers
		 * the shipped seed, and the schema is reactive so the configured
		 * vocabulary replaces it the moment it arrives.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
		 */
		async loadDecisionTypes() {
			this.decisionTypes = await listDecisionTypes()
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 load decisions for the host object. */
		async refresh() {
			if (!this.hostObjectId) {
				this.decisions = []
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.decisions = await listHostDecisions(this.hostObjectId, 100)
				await this.loadRoutes()
			} catch (e) {
				this.error =
					e?.message || t('decidiq', 'Could not load decision-making.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param formData
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create a decision pre-linked to the host.
		 */
		async onCreate(formData) {
			if (!this.hostObjectId || this.creating) {
				return
			}
			this.creating = true
			this.error = ''
			try {
				await createHostDecision(hostProposalPayload(formData, {
					register: this.hostRegister,
					schema: this.hostSchema,
					objectId: this.hostObjectId,
					label: this.objectLabel,
				}, t('decidiq', 'Proposal')))
				this.createOpen = false
				await this.refresh()
			} catch (e) {
				this.error = e?.message || t('decidiq', 'Could not create proposal.')
			} finally {
				this.creating = false
			}
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
}

.cn-decisions-tab__subtitle,
.cn-decisions-tab__empty {
	margin: 0;
	font-size: 14px;
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

.cn-decisions-tab__decision {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
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
}

.cn-decisions-tab__meta {
	font-size: 13px;
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
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cn-decisions-tab__step {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.cn-decisions-tab__step-bar {
	height: 4px;
	margin-bottom: 8px;
	border-radius: 2px;
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
	color: var(--color-text-maxcontrast);
}

.cn-decisions-tab__step--done .cn-decisions-tab__step-label,
.cn-decisions-tab__step--current .cn-decisions-tab__step-label {
	font-weight: 600;
	color: var(--color-main-text);
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
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-success-hover, var(--color-background-hover));
	color: var(--color-success-text, var(--color-main-text));
	font-size: 13px;
}

.cn-decisions-tab__notice-icon {
	flex: none;
}
</style>
