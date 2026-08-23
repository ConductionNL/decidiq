<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ConfidentialityStatusTimelineWidget — content-driven
 `confidentiality-status-timeline` catalog widget
 (register-detail-optimisation, design D3). Renders a `geheimhouding`
 record's fixed three-stage lifecycle — imposed → bekrachtiging (ratification)
 → dissolution — each stage populated when its fields are set and shown as a
 pending placeholder otherwise (REQ-EMB-010), plus the resolved ground
 (citation + legacyCitation, REQ-EMB-011) and the resolved target reference
 (REQ-EMB-012). The bekrachtiging stage carries an overdue indicator
 conveyed via icon AND text (never colour alone).

 Reads the geheimhouding fields straight off the already-loaded `objectData`
 (no query needed — every field this widget shows lives on the record
 itself); only the ground / target / decision / agenda-item REFERENCES are
 resolved via the object store. Registered as type
 "confidentiality-status-timeline" (renderer-only, surfaces: ['detail-page'])
 by registerDetailWidgets.js.

 @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
 @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
 @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type
-->
<template>
	<CnWidgetWrapper
		:title="title"
		:widgetId="widgetId"
		:refreshing="loading"
		titleIconPosition="left"
		flush>
		<template #title-icon>
			<CnIcon :name="icon" :size="20" />
		</template>

		<div class="cn-confidentiality-timeline">
			<ol
				class="cn-confidentiality-timeline__list"
				data-testid="confidentiality-timeline-list">
				<li
					v-for="stage in stages"
					:key="stage.key"
					class="cn-confidentiality-timeline__item"
					:class="{
						'cn-confidentiality-timeline__item--pending': stage.pending,
						'cn-confidentiality-timeline__item--overdue': stage.overdue,
					}"
					:data-testid="`confidentiality-stage-${stage.key}`">
					<span
						class="cn-confidentiality-timeline__icon"
						aria-hidden="true">
						<AlertOutline v-if="stage.overdue" :size="20" />
						<CheckCircleOutline v-else-if="stage.populated" :size="20" />
						<ClockOutline v-else :size="20" />
					</span>
					<div class="cn-confidentiality-timeline__body">
						<div class="cn-confidentiality-timeline__line1">
							<span class="cn-confidentiality-timeline__label">{{
								stageLabel(stage.key)
							}}</span>
							<span
								v-if="stage.overdue"
								class="cn-confidentiality-timeline__overdue-flag"
								data-testid="confidentiality-stage-overdue">
								{{ t('decidiq', 'Overdue') }}
							</span>
							<span
								v-else-if="stage.pending"
								class="cn-confidentiality-timeline__pending-flag">
								{{ t('decidiq', 'Pending') }}
							</span>
						</div>

						<template v-if="stage.key === 'imposed'">
							<span
								v-if="stage.date"
								class="cn-confidentiality-timeline__date"
								>{{ formatDateTime(stage.date) }}</span
							>
							<span
								v-if="imposedByLabel"
								class="cn-confidentiality-timeline__detail"
								>{{ imposedByLabel }}</span
							>
							<span
								v-if="imposedByBodyLabel"
								class="cn-confidentiality-timeline__detail"
								>{{ imposedByBodyLabel }}</span
							>
						</template>

						<template v-else-if="stage.key === 'ratification'">
							<span
								v-if="stage.deadline"
								class="cn-confidentiality-timeline__date">
								{{
									t('decidiq', 'Deadline {date}', {
										date: formatDate(stage.deadline),
									})
								}}
							</span>
							<span
								v-if="stage.populated && stage.date"
								class="cn-confidentiality-timeline__date">
								{{ formatDateTime(stage.date) }}
							</span>
							<NcButton
								v-if="stage.decisionId"
								variant="tertiary"
								data-testid="confidentiality-ratification-decision-link"
								@click="openRoute(decisionRoute, stage.decisionId)">
								{{
									ratificationDecisionLabel
									|| t('decidiq', 'View decision')
								}}
							</NcButton>
							<NcButton
								v-if="stage.agendaItemId"
								variant="tertiary"
								data-testid="confidentiality-ratification-agenda-link"
								@click="
									openRoute(agendaItemRoute, stage.agendaItemId)
								">
								{{
									ratificationAgendaItemLabel
									|| t('decidiq', 'View agenda item')
								}}
							</NcButton>
						</template>

						<template v-else-if="stage.key === 'dissolution'">
							<span
								v-if="stage.date"
								class="cn-confidentiality-timeline__date"
								>{{ formatDate(stage.date) }}</span
							>
							<NcButton
								v-if="stage.decisionId"
								variant="tertiary"
								data-testid="confidentiality-dissolution-decision-link"
								@click="openRoute(decisionRoute, stage.decisionId)">
								{{
									dissolutionDecisionLabel
									|| t('decidiq', 'View decision')
								}}
							</NcButton>
							<span
								v-if="stage.conditions"
								class="cn-confidentiality-timeline__detail"
								>{{ stage.conditions }}</span
							>
						</template>
					</div>
				</li>
			</ol>

			<div
				v-if="groundObj"
				class="cn-confidentiality-timeline__ground"
				data-testid="confidentiality-ground">
				<h4 class="cn-confidentiality-timeline__section-title">
					{{ t('decidiq', 'Ground') }}
				</h4>
				<p class="cn-confidentiality-timeline__citation">
					{{ groundObj.name }} — {{ groundObj.citation }}
				</p>
				<p
					v-if="groundObj.legacyCitation"
					class="cn-confidentiality-timeline__legacy-citation"
					data-testid="confidentiality-ground-legacy">
					{{
						t('decidiq', 'Formerly: {citation}', {
							citation: groundObj.legacyCitation,
						})
					}}
				</p>
			</div>

			<div
				v-if="targetLabel"
				class="cn-confidentiality-timeline__target"
				data-testid="confidentiality-target">
				<h4 class="cn-confidentiality-timeline__section-title">
					{{ targetKindLabel }}
				</h4>
				<NcButton
					v-if="targetRoute"
					variant="tertiary"
					data-testid="confidentiality-target-link"
					@click="openRoute(targetRoute, targetId)">
					{{ targetLabel }}
				</NcButton>
				<span v-else data-testid="confidentiality-target-label">{{
					targetLabel
				}}</span>
			</div>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper, useObjectStore } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import {
	buildConfidentialityStages,
	resolveObjectLabel,
} from './registerDetailWidgets.js'

const IMPOSED_BY_LABELS = {
	body: () => t('decidiq', 'Imposed by the governing body'),
	chair: () => t('decidiq', 'Imposed by the chair'),
	'executive-board': () => t('decidiq', 'Imposed by the executive board'),
}

/** Target scope → { field, schema, labelField, route }. Document has no decidiq detail route (see design.md deviation note). */
const TARGET_KINDS = {
	document: {
		field: 'targetDocument',
		schema: 'digital-document',
		labelField: 'name',
		route: '',
	},
	item: {
		field: 'targetAgendaItem',
		schema: 'agenda-item',
		labelField: 'title',
		route: 'AgendaItemDetail',
	},
	decision: {
		field: 'targetDecision',
		schema: 'decision',
		labelField: 'title',
		route: 'DecisionDetail',
	},
}

export default {
	name: 'ConfidentialityStatusTimelineWidget',

	components: {
		CnWidgetWrapper,
		CnIcon,
		NcButton,
		AlertOutline,
		CheckCircleOutline,
		ClockOutline,
	},

	props: {
		/** `{ register?, groundSchema? }` */
		content: { type: Object, default: () => ({}) },
		objectId: { type: [String, Number], default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
		objectData: { type: Object, default: () => ({}) },
		objectType: { type: String, default: '' },
		store: { type: Object, default: null },
		title: {
			type: String,
			default: () => t('decidiq', 'Confidentiality status timeline'),
		},

		icon: { type: String, default: 'ShieldLockOutline' },
	},

	data() {
		return {
			loading: false,
			groundObj: null,
			targetLabel: '',
			ratificationDecisionLabel: '',
			ratificationAgendaItemLabel: '',
			dissolutionDecisionLabel: '',
			imposedByBodyLabel: '',
		}
	},

	computed: {
		/** @spec exclude config-merge helper (register/groundSchema defaults), no behavioural requirement of its own */
		cfg() {
			const c = this.content || {}
			return {
				register: c.register || this.register || 'decidesk',
				groundSchema: c.groundSchema || 'geheimhouding-grond',
			}
		},

		/** @spec exclude widget-id plumbing for CnWidgetWrapper, no behavioural requirement of its own */
		widgetId() {
			return 'confidentiality-status-timeline'
		},

		/** @spec exclude defensive objectData accessor, no behavioural requirement of its own */
		record() {
			return this.objectData && typeof this.objectData === 'object'
				? this.objectData
				: {}
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
		 */
		stages() {
			return buildConfidentialityStages(this.record, Date.now())
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
		 */
		decisionRoute() {
			return 'DecisionDetail'
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
		 */
		agendaItemRoute() {
			return 'AgendaItemDetail'
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
		 */
		imposedByLabel() {
			const fn = IMPOSED_BY_LABELS[this.record.imposedBy]
			return fn ? fn() : this.record.imposedBy || ''
		},

		/**
		 * Whichever of targetDocument/targetAgendaItem/targetDecision is set.
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type
		 */
		targetKind() {
			if (this.record.targetDocument) return 'document'
			if (this.record.targetAgendaItem) return 'item'
			if (this.record.targetDecision) return 'decision'
			return ''
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type
		 */
		targetId() {
			const kind = TARGET_KINDS[this.targetKind]
			return kind ? this.record[kind.field] : null
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type
		 */
		targetRoute() {
			const kind = TARGET_KINDS[this.targetKind]
			return (kind && kind.route) || ''
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type
		 */
		targetKindLabel() {
			const labels = {
				document: t('decidiq', 'Target document'),
				item: t('decidiq', 'Target agenda item'),
				decision: t('decidiq', 'Target decision'),
			}
			return labels[this.targetKind] || ''
		},
	},

	watch: {
		record: {
			immediate: true,
			/**
			 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
			 */
			handler() {
				this.resolveReferences()
			},
		},
	},

	methods: {
		t,

		/** @spec exclude store-resolution plumbing, no behavioural requirement of its own */
		getStore() {
			if (this.store) return this.store
			try {
				return useObjectStore()
			} catch {
				return null
			}
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
		 * @param {string} key Stage key (imposed/ratification/dissolution).
		 * @return {string}
		 */
		stageLabel(key) {
			const labels = {
				imposed: t('decidiq', 'Imposed'),
				ratification: t('decidiq', 'Ratification'),
				dissolution: t('decidiq', 'Dissolution'),
			}
			return labels[key] || key
		},

		/**
		 * @spec exclude presentation helper for date formatting, no behavioural requirement of its own
		 * @param {string} value ISO date string.
		 * @return {string}
		 */
		formatDate(value) {
			if (!value) return ''
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString()
		},

		/**
		 * @spec exclude presentation helper for date+time formatting, no behavioural requirement of its own
		 * @param {string} value ISO date string.
		 * @return {string}
		 */
		formatDateTime(value) {
			if (!value) return ''
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString()
		},

		/**
		 * Resolve the ground object, the target label, and every Decision /
		 * AgendaItem link label the timeline shows. Runs once per loaded
		 * record (watch immediate); a missing store degrades to raw ids
		 * rather than throwing.
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type
		 * @return {Promise<void>}
		 */
		async resolveReferences() {
			const store = this.getStore()
			if (!store) return
			const r = this.record
			if (!r || !r.id) return
			this.loading = true
			try {
				const { register, groundSchema } = this.cfg
				const tasks = []

				if (r.ground) {
					tasks.push(
						(async () => {
							if (
								!store.objectTypeRegistry
								|| !store.objectTypeRegistry[groundSchema]
							) {
								store.registerObjectType(
									groundSchema,
									groundSchema,
									register,
								)
							}
							this.groundObj = await store.fetchObject(
								groundSchema,
								r.ground,
							)
						})(),
					)
				} else {
					this.groundObj = null
				}

				if (r.imposedByBody) {
					tasks.push(
						resolveObjectLabel(
							store,
							'governance-body',
							'governance-body',
							register,
							r.imposedByBody,
							'name',
						).then((label) => {
							this.imposedByBodyLabel = label
						}),
					)
				} else {
					this.imposedByBodyLabel = ''
				}

				if (r.ratificationDecision) {
					tasks.push(
						resolveObjectLabel(
							store,
							'decision',
							'decision',
							register,
							r.ratificationDecision,
							'title',
						).then((label) => {
							this.ratificationDecisionLabel = label
						}),
					)
				} else {
					this.ratificationDecisionLabel = ''
				}

				if (r.ratificationAgendaItem) {
					tasks.push(
						resolveObjectLabel(
							store,
							'agenda-item',
							'agenda-item',
							register,
							r.ratificationAgendaItem,
							'title',
						).then((label) => {
							this.ratificationAgendaItemLabel = label
						}),
					)
				} else {
					this.ratificationAgendaItemLabel = ''
				}

				if (r.dissolutionDecision) {
					tasks.push(
						resolveObjectLabel(
							store,
							'decision',
							'decision',
							register,
							r.dissolutionDecision,
							'title',
						).then((label) => {
							this.dissolutionDecisionLabel = label
						}),
					)
				} else {
					this.dissolutionDecisionLabel = ''
				}

				const kind = TARGET_KINDS[this.targetKind]
				if (kind) {
					tasks.push(
						resolveObjectLabel(
							store,
							kind.schema,
							kind.schema,
							register,
							this.record[kind.field],
							kind.labelField,
						).then((label) => {
							this.targetLabel = label
						}),
					)
				} else {
					this.targetLabel = ''
				}

				await Promise.all(tasks)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
		 * @param {string} routeName Vue-router route name.
		 * @param {string} id Object id to navigate to.
		 * @return {void}
		 */
		openRoute(routeName, id) {
			if (!routeName || !id) return
			this.$router.push({ name: routeName, params: { id } })
		},
	},
}
</script>

<style scoped>
.cn-confidentiality-timeline__list {
	list-style: none;
	margin: 0;
	padding: calc(2 * var(--default-grid-baseline, 4px));
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-confidentiality-timeline__item {
	display: flex;
	gap: 10px;
}

.cn-confidentiality-timeline__item--pending {
	opacity: 0.7;
}

/* Default = populated stage (success); pending and overdue override via
   the cascade — no :not() chains, which prettier and stylelint wrap
   incompatibly. */
.cn-confidentiality-timeline__icon {
	flex-shrink: 0;
	margin-top: 2px;
	color: var(--color-success);
}

.cn-confidentiality-timeline__item--pending .cn-confidentiality-timeline__icon {
	color: var(--color-text-maxcontrast);
}

.cn-confidentiality-timeline__item--overdue .cn-confidentiality-timeline__icon {
	color: var(--color-error);
}

.cn-confidentiality-timeline__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.cn-confidentiality-timeline__line1 {
	display: flex;
	align-items: center;
	gap: 8px;
}

.cn-confidentiality-timeline__label {
	font-weight: bold;
}

.cn-confidentiality-timeline__overdue-flag {
	display: inline-flex;
	align-items: center;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-error);
	color: var(--color-primary-text, #fff);
	font-size: 0.8em;
}

.cn-confidentiality-timeline__pending-flag {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.cn-confidentiality-timeline__date,
.cn-confidentiality-timeline__detail {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.cn-confidentiality-timeline__ground,
.cn-confidentiality-timeline__target {
	padding: 0 calc(2 * var(--default-grid-baseline, 4px))
		calc(2 * var(--default-grid-baseline, 4px));
}

.cn-confidentiality-timeline__section-title {
	margin: 0 0 4px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	color: var(--color-text-maxcontrast);
}

.cn-confidentiality-timeline__citation {
	margin: 0;
}

.cn-confidentiality-timeline__legacy-citation {
	margin: 2px 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
