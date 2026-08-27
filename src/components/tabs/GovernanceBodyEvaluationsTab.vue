<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: board self-evaluation cycles for a Governance Body
 (board-self-evaluation).

 Lists the body's BoardEvaluation cycles (draft/open/closed/published),
 lets a chair/secretary start a cycle (OpenRegister RBAC gates the
 opening/closing lifecycle write — no app-local authorization check here),
 lets any invited member submit an anonymous response, and — once closed —
 shows the per-dimension/overall score (client-side CSS bars, following the
 same dependency-free rendering GovernanceBodyEfficiencyTab already uses for
 its "analytics leaf" surface in this codebase) with automatic small-body
 suppression, plus report-generation and opt-in publish actions.

 @spec openspec/specs/board-self-evaluation/spec.md
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--evaluations"
		data-testid="body-evaluations-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Self-evaluation') }}
			</h3>
			<NcButton
				v-if="templates.length"
				data-testid="body-evaluations-start"
				@click="startEvaluation">
				{{ t('decidiq', 'Start evaluation') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load evaluations')">
			{{ error }}
		</CnNoteCard>

		<div v-if="loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Loading…') }}
		</div>

		<template v-else-if="!evaluations.length">
			<p class="decidiq-tab__empty" data-testid="body-evaluations-empty">
				{{ t('decidiq', 'No self-evaluation cycles yet for this body.') }}
			</p>
		</template>

		<ul v-else class="evaluation-list" role="list">
			<li
				v-for="evaluation in sortedEvaluations"
				:key="evaluation.id"
				class="evaluation-card"
				:data-testid="`evaluation-card-${evaluation.id}`">
				<div class="evaluation-card__header">
					<strong>{{ evaluation.cycleLabel }}</strong>
					<span class="evaluation-card__status">{{
						evaluation.lifecycle
					}}</span>
				</div>

				<p class="evaluation-card__meta">
					{{
						t('decidiq', '{responded} of {invited} responded', {
							responded: evaluation.respondedCount || 0,
							invited: evaluation.invitedMemberCount || 0,
						})
					}}
				</p>

				<div class="evaluation-card__actions">
					<NcButton
						v-if="evaluation.lifecycle === 'draft'"
						data-testid="evaluation-open"
						@click="openEvaluation(evaluation)">
						{{ t('decidiq', 'Open for responses') }}
					</NcButton>
					<NcButton
						v-if="evaluation.lifecycle === 'open'"
						data-testid="evaluation-respond"
						@click="beginRespond(evaluation)">
						{{ t('decidiq', 'Respond anonymously') }}
					</NcButton>
					<NcButton
						v-if="evaluation.lifecycle === 'open'"
						data-testid="evaluation-close"
						@click="closeEvaluation(evaluation)">
						{{ t('decidiq', 'Close cycle') }}
					</NcButton>
					<NcButton
						v-if="evaluation.lifecycle === 'closed'"
						data-testid="evaluation-publish"
						@click="publishEvaluationResults(evaluation)">
						{{ t('decidiq', 'Publish summary') }}
					</NcButton>
					<NcButton
						v-if="
							evaluation.lifecycle === 'closed'
							|| evaluation.lifecycle === 'published'
						"
						data-testid="evaluation-report"
						@click="generateReport(evaluation)">
						{{ t('decidiq', 'Generate report') }}
					</NcButton>
				</div>

				<div
					v-if="scoreSummaryFor(evaluation)"
					class="evaluation-card__results"
					:data-testid="`evaluation-results-${evaluation.id}`">
					<p class="evaluation-card__overall">
						{{
							t('decidiq', 'Overall score: {score}', {
								score:
									scoreSummaryFor(evaluation).overallScore ?? '—',
							})
						}}
					</p>

					<p
						v-if="scoreSummaryFor(evaluation).suppressed"
						class="evaluation-card__suppressed"
						data-testid="evaluation-suppressed-note">
						{{
							t(
								'decidiq',
								'Per-dimension and free-text breakdowns are hidden: too few respondents to protect anonymity.',
							)
						}}
					</p>

					<ul v-else class="efficiency-bars" role="list">
						<li
							v-for="(score, dimension) in scoreSummaryFor(evaluation)
								.dimensionScores"
							:key="dimension"
							class="efficiency-bars__row"
							role="listitem">
							<span class="efficiency-bars__label">{{
								dimension
							}}</span>
							<span class="efficiency-bars__track">
								<span
									class="efficiency-bars__fill"
									:style="{ width: barWidth(score, 5) }" />
							</span>
							<span class="efficiency-bars__value">{{ score }}</span>
						</li>
					</ul>
				</div>
			</li>
		</ul>

		<EvaluationRespondModal
			v-if="showRespondModal"
			:questions="activeTemplateQuestions"
			@close="showRespondModal = false"
			@confirm="submitResponse" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import EvaluationRespondModal from '../../modals/EvaluationRespondModal.vue'
import {
	closeEvaluation as closeEvaluationApi,
	generateEvaluationReport,
	publishEvaluation,
	respondToEvaluation,
} from '../../services/boardEvaluationApi.js'
import { ensureRelationType } from './useRelationStore.js'

/**
 * @spec openspec/specs/board-self-evaluation/spec.md
 */
export default {
	name: 'GovernanceBodyEvaluationsTab',

	components: { NcButton, CnNoteCard, EvaluationRespondModal },

	inject: {
		/**
		 * CnDetailPage's reactive `{ objectId, object, register, schema }`
		 * holder.
		 *
		 * This tab is declared in the manifest as a `type: "custom"` body
		 * widget, and CnDetailPage's `widget-<id>` slot binds ONLY
		 * `{ item, widget }` — never the page's object id. Without this
		 * injection the `objectId` prop is empty on that mount path, `refresh()`
		 * returns before issuing a single request, and the tab renders "No
		 * self-evaluation cycles yet for this body." for a body that has them.
		 * This is the same holder the declarative `@objectId` filter token
		 * resolves against.
		 */
		cnObjectContext: { default: null },
	},

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			evaluations: [],
			templates: [],
			participants: [],
			showRespondModal: false,
			activeEvaluation: null,
		}
	},

	computed: {
		/**
		 * The GovernanceBody this tab acts on: the explicit `objectId` prop when
		 * mounted directly, otherwise the id CnDetailPage provides on
		 * `cnObjectContext` (manifest body-widget mount, where no id prop is
		 * bound).
		 *
		 * @return {string} The governance-body UUID, or '' when not resolvable.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-001-board-evaluation-cycle-bound-to-a-governance-body
		 */
		resolvedObjectId() {
			if (this.objectId) {
				return String(this.objectId)
			}
			const context = this.cnObjectContext
			// Vue unwraps an injected ref for the Options API, but the compat
			// build can hand back the ref itself — accept both shapes.
			const value =
				context && typeof context === 'object' && 'value' in context
					? context.value
					: context
			return value && value.objectId ? String(value.objectId) : ''
		},

		/** @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-001-board-evaluation-cycle-bound-to-a-governance-body */
		sortedEvaluations() {
			return [...this.evaluations].sort((a, b) =>
				(b.cycleLabel || '').localeCompare(a.cycleLabel || ''),
			)
		},

		/** @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-002-reusable-questionnaire-template-organised-by-effectiveness-dimensions */
		activeTemplateQuestions() {
			if (!this.activeEvaluation) return []
			const template = this.templates.find(
				(tpl) => tpl.id === this.activeEvaluation.template,
			)
			return (template && template.questions) || []
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/** @spec openspec/specs/board-self-evaluation/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/board-self-evaluation/spec.md */
		async refresh() {
			if (!this.resolvedObjectId) return
			const bodyId = this.resolvedObjectId
			this.loading = true
			this.error = ''
			try {
				const evaluationStore = ensureRelationType('board-evaluation')
				const evaluations = await evaluationStore.fetchCollection(
					'board-evaluation',
					{ _limit: 100 },
				)
				this.evaluations = (evaluations || []).filter(
					(e) =>
						e?.governanceBody === bodyId
						|| e?.['@self']?.relations?.governanceBody === bodyId,
				)

				const templateStore = ensureRelationType('evaluation-template')
				this.templates = await templateStore.fetchCollection(
					'evaluation-template',
					{ _limit: 100 },
				)

				const participantStore = ensureRelationType('participant')
				const participants = await participantStore.fetchCollection(
					'participant',
					{ _limit: 500 },
				)
				this.participants = (participants || []).filter(
					(p) => p?.governanceBody === bodyId,
				)
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load evaluations.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the materialised scoreSummary, whichever shape it arrives in.
		 *
		 * The schema declares `scoreSummary` as a `string` holding JSON, and
		 * BoardEvaluationScoreService writes it as one — but OpenRegister hands
		 * it back to the client ALREADY PARSED, as an object. `JSON.parse()` on
		 * that object stringifies it to "[object Object]" first, throws, and the
		 * catch turned every real score summary into `null`: the results block
		 * is `v-if`'d on this method, so a closed cycle with a perfectly good
		 * summary — including the seeded 2025 comparison cycle — rendered no
		 * score at all, with no error anywhere. Measured on a live instance:
		 * `GET /api/objects/decidiq/board-evaluation` returns
		 * `scoreSummary: {overallScore: 3.6, …}`, an object.
		 *
		 * Accept both shapes rather than betting on one.
		 *
		 * @param {object} evaluation The BoardEvaluation object.
		 * @return {object|null} The summary, or null when absent/unreadable.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
		 */
		scoreSummaryFor(evaluation) {
			const summary = evaluation.scoreSummary
			if (!summary) return null
			if (typeof summary === 'object') return summary
			try {
				return JSON.parse(summary)
			} catch {
				return null
			}
		},

		/**
		 * @param {number} value The score value.
		 * @param {number} max The scale maximum.
		 * @return {string} A CSS width percentage.
		 */
		barWidth(value, max) {
			if (!Number.isFinite(value) || !Number.isFinite(max) || max <= 0)
				return '0%'
			return `${Math.round((value / max) * 100)}%`
		},

		/**
		 * Create a draft BoardEvaluation for the default template, denormalising
		 * the body's current chair/secretary NC UIDs onto chairUserId/
		 * secretaryUserId (consumed only by the register's lifecycle RBAC rule)
		 * and the current roster onto invitedParticipantIds.
		 *
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-001-board-evaluation-cycle-bound-to-a-governance-body
		 */
		async startEvaluation() {
			const template = this.templates[0]
			if (!template) return

			const chair = this.participants.find((p) =>
				['chair', 'chairman'].includes(p.role),
			)
			const secretary = this.participants.find((p) => p.role === 'secretary')
			const invitedParticipantIds = this.participants.map((p) => p.id)

			try {
				const store = ensureRelationType('board-evaluation')
				await store.saveObject('board-evaluation', {
					governanceBody: this.resolvedObjectId,
					template: template.id,
					cycleLabel: String(new Date().getFullYear()),
					lifecycle: 'draft',
					invitedMemberCount: invitedParticipantIds.length,
					respondedCount: 0,
					invitedParticipantIds,
					respondedParticipantIds: [],
					chairUserId: (chair && chair.nextcloudUserId) || '',
					secretaryUserId: (secretary && secretary.nextcloudUserId) || '',
				})
				await this.refresh()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to start the evaluation.')
			}
		},

		/**
		 * @param {object} evaluation The draft BoardEvaluation to open.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
		 */
		async openEvaluation(evaluation) {
			try {
				const store = ensureRelationType('board-evaluation')
				await store.saveObject('board-evaluation', {
					...evaluation,
					lifecycle: 'open',
					openedAt: new Date().toISOString(),
				})
				await this.refresh()
			} catch (e) {
				this.error =
					e?.message
					|| this.t(
						'decidiq',
						'Only the chair or secretary can open this cycle.',
					)
			}
		},

		/** @param {object} evaluation The open BoardEvaluation to respond to. */
		beginRespond(evaluation) {
			this.activeEvaluation = evaluation
			this.showRespondModal = true
		},

		/**
		 * @param {Array<object>} answers The answers[] payload from the modal.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
		 */
		async submitResponse(answers) {
			this.showRespondModal = false
			if (!this.activeEvaluation) return
			try {
				await respondToEvaluation(this.activeEvaluation.id, answers)
				await this.refresh()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('decidiq', 'Failed to submit your response.')
			}
		},

		/**
		 * @param {object} evaluation The open BoardEvaluation to close.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
		 */
		async closeEvaluation(evaluation) {
			try {
				await closeEvaluationApi(evaluation.id)
				await this.refresh()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('decidiq', 'Failed to close the cycle.')
			}
		},

		/**
		 * @param {object} evaluation The closed BoardEvaluation to publish.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
		 */
		async publishEvaluationResults(evaluation) {
			try {
				await publishEvaluation(evaluation.id)
				await this.refresh()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('decidiq', 'Failed to publish the summary.')
			}
		},

		/**
		 * @param {object} evaluation The closed/published BoardEvaluation.
		 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
		 */
		async generateReport(evaluation) {
			try {
				await generateEvaluationReport(evaluation.id)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('decidiq', 'Failed to generate the report.')
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__empty,
.decidiq-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.evaluation-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.evaluation-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.evaluation-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.evaluation-card__status {
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.evaluation-card__meta {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.evaluation-card__actions {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.evaluation-card__results {
	margin-top: 6px;
}

.evaluation-card__overall {
	font-weight: bold;
	margin: 0 0 4px 0;
}

.evaluation-card__suppressed {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin: 0;
}

.efficiency-bars {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.efficiency-bars__row {
	display: grid;
	grid-template-columns: 8rem 1fr 4rem;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.efficiency-bars__label {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.efficiency-bars__track {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	height: 12px;
	overflow: hidden;
}

.efficiency-bars__fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
}

.efficiency-bars__value {
	font-variant-numeric: tabular-nums;
	text-align: right;
}
</style>
