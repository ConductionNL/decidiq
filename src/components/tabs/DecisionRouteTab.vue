<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: the decision's route timeline (decision-detail-fullpicture C6,
 Part A + the effective-status banner from Part B/D5).

 Posture: read-only. Renders the Decision's ordered DecisionStage route as a
 timeline — sequence, label, decision-maker name (resolved from assignedBody /
 assignedPerson per decisionMakerType), stageType, method, status, outcome,
 decidedAt — highlights the stage equal to the Decision's currentStage, and
 shows "N of M stages decided" progress. Resolving a stage is owned by
 decision-methods (C5), not this view.

 The effective-status banner above the timeline is derived CLIENT-SIDE
 (design D2): OpenRegister's CalculationEvaluator has no inverse/reverse
 relation operator, so the inbound "which decided/enacted decision
 supersedes/repeals this one" lookup cannot be a materialised calculation.
 We query decisions whose supersedes/repeals array contains this id, filter
 to lifecycle ∈ {decided, enacted}, precedence repealed > superseded.

 @spec openspec/specs/decision-route/spec.md
-->
<template>
	<div class="decidiq-tab decidiq-tab--route" data-testid="decision-route-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Route') }}
			</h3>
			<CnStatusBadge
				v-if="lifecycle"
				:label="lifecycleLabel"
				:colorMap="lifecycleColors"
				data-testid="route-lifecycle-badge" />
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load route')">
			{{ error }}
		</CnNoteCard>

		<!-- Effective-status banner (client-derived, design D2/D5). -->
		<CnNoteCard
			v-if="!error && effectingDecision"
			:type="effectiveStatus === 'repealed' ? 'error' : 'warning'"
			:title="effectiveStatusTitle"
			data-testid="effective-status-banner">
			{{ effectiveStatusMessage }}
			<NcButton
				variant="tertiary"
				class="decidiq-route__banner-link"
				data-testid="effective-status-navigate"
				:aria-label="
					t('decidiq', 'Open the decision that replaced this one')
				"
				@click="openDecision(effectingDecision)">
				{{ effectingDecision.title || t('decidiq', 'View decision') }}
			</NcButton>
		</CnNoteCard>

		<p v-if="!error && loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Loading route…') }}
		</p>

		<!-- Progress + empty state. -->
		<template v-if="!error && !loading">
			<CnNoteCard
				v-if="!stages.length"
				type="info"
				:title="t('decidiq', 'No staged route configured')"
				data-testid="route-empty">
				{{
					t(
						'decidiq',
						'This decision has no staged route. A stageless decision is valid.',
					)
				}}
			</CnNoteCard>

			<template v-else>
				<div class="decidiq-route__progress" data-testid="route-progress">
					<span class="decidiq-route__progress-dots" aria-hidden="true">
						<span
							v-for="(s, i) in stages"
							:key="'dot-' + i"
							class="decidiq-route__progress-dot"
							:class="{
								'decidiq-route__progress-dot--done':
									s.status === 'decided' || s.status === 'skipped',
							}" />
					</span>
					<span class="decidiq-route__progress-label">
						{{
							t('decidiq', '{decided} of {total} stages decided', {
								decided: decidedCount,
								total: stages.length,
							})
						}}
					</span>
				</div>

				<ol class="decidiq-route__timeline" data-testid="route-timeline">
					<li
						v-for="stage in stages"
						:key="stage.id"
						class="decidiq-route__step"
						:class="{
							'decidiq-route__step--current': isCurrent(stage),
						}"
						:data-testid="'route-stage-' + stage.sequence">
						<span
							class="decidiq-route__marker"
							:class="'decidiq-route__marker--' + stage.status"
							aria-hidden="true" />
						<div class="decidiq-route__body">
							<div class="decidiq-route__line1">
								<span class="decidiq-route__seq">{{
									t('decidiq', 'seq {n}', { n: stage.sequence })
								}}</span>
								<span class="decidiq-route__maker">{{
									makerName(stage)
								}}</span>
								<span class="decidiq-route__meta"
									>{{ stageTypeLabel(stage.stageType) }} ·
									{{ methodLabel(stage.method) }}</span
								>
								<CnStatusBadge
									v-if="isCurrent(stage)"
									:label="t('decidiq', 'Current')"
									:colorMap="{
										[t('decidiq', 'Current')]: 'primary',
									}" />
							</div>
							<div class="decidiq-route__line2">
								<CnStatusBadge
									:label="statusLabel(stage.status)"
									:colorMap="statusColors" />
								<span
									v-if="stage.outcome"
									class="decidiq-route__outcome"
									>{{ outcomeLabel(stage.outcome) }}</span
								>
								<span
									v-if="stage.decidedAt"
									class="decidiq-route__date"
									>{{ formatDate(stage.decidedAt) }}</span
								>
								<span
									v-if="stage.label"
									class="decidiq-route__stage-label"
									>{{ stage.label }}</span
								>
							</div>
						</div>
					</li>
				</ol>

				<p
					v-if="currentStageObj"
					class="decidiq-route__todo"
					data-testid="route-todo">
					{{
						t('decidiq', 'Still to do: stage {seq} ({maker})', {
							seq: currentStageObj.sequence,
							maker: makerName(currentStageObj),
						})
					}}
					<span v-if="openActionItemCount > 0">
						·
						{{
							t('decidiq', '{n} open action items', {
								n: openActionItemCount,
							})
						}}
					</span>
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'DecisionRouteTab',
	components: { CnNoteCard, CnStatusBadge, NcButton },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			lifecycle: '',
			currentStage: '',
			stages: [],
			effectiveStatus: '',
			effectingDecision: null,
			openActionItemCount: 0,
		}
	},

	computed: {
		/** @spec openspec/specs/decision-route/spec.md */
		decidedCount() {
			return this.stages.filter(
				(s) => s.status === 'decided' || s.status === 'skipped',
			).length
		},

		/** @spec openspec/specs/decision-route/spec.md */
		currentStageObj() {
			return this.stages.find((s) => this.isCurrent(s)) || null
		},

		lifecycleLabel() {
			return this.stateLabel(this.lifecycle)
		},

		lifecycleColors() {
			return {
				[this.stateLabel('draft')]: 'default',
				[this.stateLabel('proposed')]: 'primary',
				[this.stateLabel('deliberating')]: 'warning',
				[this.stateLabel('voting')]: 'warning',
				[this.stateLabel('decided')]: 'success',
				[this.stateLabel('enacted')]: 'success',
				[this.stateLabel('archived')]: 'default',
			}
		},

		statusColors() {
			return {
				[this.statusLabel('pending')]: 'default',
				[this.statusLabel('active')]: 'primary',
				[this.statusLabel('decided')]: 'success',
				[this.statusLabel('skipped')]: 'default',
			}
		},

		effectiveStatusTitle() {
			return this.effectiveStatus === 'repealed'
				? this.t('decidiq', 'Repealed')
				: this.t('decidiq', 'Superseded')
		},

		effectiveStatusMessage() {
			const date =
				this.effectingDecision?.enactedAt
				|| this.effectingDecision?.decisionDate
			const when = date ? this.formatDate(date) : ''
			return this.effectiveStatus === 'repealed'
				? this.t('decidiq', 'This decision was repealed{by}.', {
						by: when
							? ' ' + this.t('decidiq', 'on {date}', { date: when })
							: '',
					})
				: this.t('decidiq', 'This decision was superseded{by}.', {
						by: when
							? ' ' + this.t('decidiq', 'on {date}', { date: when })
							: '',
					})
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/decision-route/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * @param stage
		 * @spec openspec/specs/decision-route/spec.md
		 */
		isCurrent(stage) {
			return (
				!!this.currentStage
				&& (stage.id === this.currentStage
					|| stage.uuid === this.currentStage)
			)
		},

		stateLabel(state) {
			const labels = {
				draft: this.t('decidiq', 'Draft'),
				proposed: this.t('decidiq', 'Proposed'),
				deliberating: this.t('decidiq', 'Deliberating'),
				voting: this.t('decidiq', 'Voting'),
				decided: this.t('decidiq', 'Decided'),
				enacted: this.t('decidiq', 'Enacted'),
				archived: this.t('decidiq', 'Archived'),
			}
			return labels[state] || state
		},

		stageTypeLabel(type) {
			const labels = {
				preparatory: this.t('decidiq', 'preparatory'),
				advisory: this.t('decidiq', 'advisory'),
				decisive: this.t('decidiq', 'decisive'),
				ratifying: this.t('decidiq', 'ratifying'),
			}
			return labels[type] || type || ''
		},

		methodLabel(method) {
			const labels = {
				manual: this.t('decidiq', 'manual'),
				vote: this.t('decidiq', 'vote'),
				signature: this.t('decidiq', 'signature'),
				'chair-register': this.t('decidiq', 'chair register'),
				advice: this.t('decidiq', 'advice'),
			}
			return labels[method] || method || ''
		},

		statusLabel(status) {
			const labels = {
				pending: this.t('decidiq', 'pending'),
				active: this.t('decidiq', 'active'),
				decided: this.t('decidiq', 'decided'),
				skipped: this.t('decidiq', 'skipped'),
			}
			return labels[status] || status || ''
		},

		outcomeLabel(outcome) {
			const labels = {
				for: this.t('decidiq', 'for'),
				against: this.t('decidiq', 'against'),
				adopted: this.t('decidiq', 'adopted'),
				rejected: this.t('decidiq', 'rejected'),
				advised: this.t('decidiq', 'advised'),
				deferred: this.t('decidiq', 'deferred'),
			}
			return labels[outcome] || outcome || ''
		},

		/**
		 * @param stage
		 * @spec openspec/specs/decision-route/spec.md
		 */
		makerName(stage) {
			const ref =
				stage?.decisionMakerType === 'person'
					? stage.assignedPerson
					: stage.assignedBody
			if (!ref) return this.t('decidiq', 'Unassigned')
			if (typeof ref === 'object')
				return (
					ref.name
					|| ref.title
					|| ref.displayName
					|| this.t('decidiq', 'Unassigned')
				)
			// Reference is an id we did not expand; show a stable fallback.
			return this.t('decidiq', 'Decision maker')
		},

		/**
		 * @param value
		 * @spec openspec/specs/decision-route/spec.md
		 */
		formatDate(value) {
			if (!value) return ''
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString()
		},

		/** @spec openspec/specs/decision-route/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			this.effectingDecision = null
			this.effectiveStatus = ''
			try {
				const decisionStore = ensureRelationType('decision')
				const decision = await decisionStore.fetchObject(
					'decision',
					this.objectId,
				)
				this.lifecycle = decision?.lifecycle || ''
				this.currentStage = decision?.currentStage || ''

				const stageStore = ensureRelationType('decision-stage')
				const stages = await stageStore.fetchCollection('decision-stage', {
					decision: this.objectId,
					_limit: 100,
				})
				this.stages = (stages || [])
					.slice()
					.sort((a, b) => (a.sequence || 0) - (b.sequence || 0))

				await this.deriveEffectiveStatus(decision)
				await this.countOpenActionItems()
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Failed to load route.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Client-side effective-status derivation (design D2): find a
		 * decided/enacted decision whose supersedes/repeals array contains this
		 * decision's id. repealed takes precedence over superseded.
		 *
		 * @param {object} decision The current decision object.
		 * @return {Promise<void>}
		 * @spec openspec/specs/decision-route/spec.md
		 */
		async deriveEffectiveStatus(decision) {
			const selfId = decision?.id || decision?.uuid || String(this.objectId)
			const store = ensureRelationType('decision')
			// Effect-bearing sources must be decided or enacted.
			const candidates = await store.fetchCollection('decision', {
				lifecycle: ['decided', 'enacted'],
				_limit: 200,
			})
			const containsSelf = (rel) =>
				Array.isArray(rel)
				&& rel.some(
					(r) => (typeof r === 'object' ? r.id || r.uuid : r) === selfId,
				)

			const repealer = (candidates || []).find((d) => containsSelf(d.repeals))
			if (repealer) {
				this.effectiveStatus = 'repealed'
				this.effectingDecision = repealer
				return
			}
			const superseder = (candidates || []).find((d) =>
				containsSelf(d.supersedes),
			)
			if (superseder) {
				this.effectiveStatus = 'superseded'
				this.effectingDecision = superseder
			}
		},

		/** @spec openspec/specs/decision-route/spec.md */
		async countOpenActionItems() {
			try {
				const store = ensureRelationType('action-item')
				const items = await store.fetchCollection('action-item', {
					decision: this.objectId,
					_limit: 100,
				})
				this.openActionItemCount = (items || []).filter(
					(i) =>
						i.status && i.status !== 'done' && i.status !== 'completed',
				).length
			} catch (e) {
				// Action-item count is supplementary; never block the timeline.
				this.openActionItemCount = 0
			}
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decision-route/spec.md
		 */
		openDecision(decision) {
			const id = decision?.id || decision?.uuid
			if (!id) return
			this.$router.push({ name: 'DecisionDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidiq-route__banner-link {
	margin-top: 4px;
}

.decidiq-route__progress {
	display: flex;
	align-items: center;
	gap: 8px;
}

.decidiq-route__progress-dots {
	display: inline-flex;
	gap: 4px;
}

.decidiq-route__progress-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
}

.decidiq-route__progress-dot--done {
	background: var(--color-success);
	border-color: var(--color-success);
}

.decidiq-route__progress-label {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.decidiq-route__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.decidiq-route__step {
	display: flex;
	gap: 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
}

.decidiq-route__step--current {
	background: var(--color-primary-element-light);
}

.decidiq-route__marker {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
	flex-shrink: 0;
	margin-top: 4px;
}

.decidiq-route__marker--decided,
.decidiq-route__marker--skipped {
	background: var(--color-success);
	border-color: var(--color-success);
}

.decidiq-route__marker--active {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.decidiq-route__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.decidiq-route__line1,
.decidiq-route__line2 {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.decidiq-route__seq {
	font-weight: bold;
}

.decidiq-route__meta,
.decidiq-route__date,
.decidiq-route__stage-label {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.decidiq-route__todo {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}
</style>
