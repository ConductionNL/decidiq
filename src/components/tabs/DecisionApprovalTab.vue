<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Decision page: send the proposal along an approval route and follow it.

 The approval chain leaf shows the route's progress and lets the person
 whose turn it is act; the button starts a route. After a send the leaf is
 remounted so it reads the new stages. Above the chain the approver reads
 the decision's risk band, its score and the properties that produced it.

 @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 @spec openspec/changes/archive/2026-11-20-routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
-->
<template>
	<div class="decision-approval">
		<NcButton
			variant="secondary"
			data-testid="decision-send-for-approval"
			:disabled="!objectId"
			@click="dialogOpen = true">
			{{ t('decidiq', 'Send for approval') }}
		</NcButton>
		<section
			v-if="risk"
			class="decision-approval__risk"
			data-testid="decision-risk-assessment">
			<strong>
				{{ risk.band
					? t('decidiq', 'Risk band {band}', { band: risk.band })
					: t('decidiq', 'No risk band') }}
			</strong>
			<span>{{ t('decidiq', 'Score {score}, from:', { score: risk.score }) }}</span>
			<ul class="decision-approval__risk-basis">
				<li v-for="row in risk.basis" :key="row.property">
					{{ t('decidiq', '{property}: {value} (+{points})', row) }}
				</li>
			</ul>
		</section>
		<CnApprovalChainWidget
			:key="chainKey"
			register="decidiq"
			schema="decision"
			:objectId="objectId"
			:collapsible="false" />
		<SendForApprovalDialog
			v-if="dialogOpen"
			:decisionId="objectId"
			@sent="onSent"
			@close="dialogOpen = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import SendForApprovalDialog from '../../dialogs/SendForApprovalDialog.vue'
import CnApprovalChainWidget from '../../integrations/CnApprovalChainWidget.vue'
import { riskSummary } from '../../utils/riskAssessment.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'DecisionApprovalTab',

	components: { CnApprovalChainWidget, NcButton, SendForApprovalDialog },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return { dialogOpen: false, chainKey: 0, risk: null }
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Read the risk again for another decision.
			 *
			 * @return {void}
			 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
			 */
			handler() {
				this.loadRisk()
			},
		},
	},

	methods: {
		/**
		 * Read the decision's risk assessment for the approver. A decision
		 * without one, or a read that fails, shows no risk block.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
		 */
		async loadRisk() {
			if (!this.objectId) {
				this.risk = null
				return
			}
			try {
				const decision = await ensureRelationType('decision').fetchObject('decision', this.objectId)
				this.risk = riskSummary(decision)
			} catch {
				this.risk = null
			}
		},

		/**
		 * Close the dialog and let the chain read the new route.
		 *
		 * @spec openspec/changes/archive/2026-11-20-routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
		 */
		onSent() {
			this.dialogOpen = false
			this.chainKey += 1
			this.loadRisk()
		},
	},
}
</script>

<style scoped>
.decision-approval {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-start;
}

.decision-approval__risk {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decision-approval__risk-basis {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}
</style>
