<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Decision page: send the proposal along an approval route and follow it.

 The approval chain leaf shows the route's progress and lets the person
 whose turn it is act; the button starts a route. After a send the leaf is
 remounted so it reads the new stages.

 @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
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

export default {
	name: 'DecisionApprovalTab',

	components: { CnApprovalChainWidget, NcButton, SendForApprovalDialog },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return { dialogOpen: false, chainKey: 0 }
	},

	methods: {
		/**
		 * Close the dialog and let the chain read the new route.
		 *
		 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
		 */
		onSent() {
			this.dialogOpen = false
			this.chainKey += 1
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
</style>
