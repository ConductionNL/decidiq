<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Hub-wide staff moderation queue (consultations-engagement-hub). Lists every
 pending ConsultationReaction across all consultations by mounting the shared
 ConsultationReactionsTab with no objectId. The same component, scoped via
 objectId, is the per-consultation "Reactions" tab on the consultation detail.

 @spec openspec/changes/consultations-engagement-hub/specs/p3-citizen-participation/spec.md
-->
<template>
	<div class="moderation-queue" data-testid="moderation-queue-page">
		<header class="moderation-queue__header">
			<h2>{{ t('decidesk', 'Reaction moderation queue') }}</h2>
			<p>{{ t('decidesk', 'Approve or reject citizen reactions before they count toward a consultation.') }}</p>
		</header>

		<ConsultationReactionsTab />
	</div>
</template>

<script>
import ConsultationReactionsTab from '../../components/tabs/ConsultationReactionsTab.vue'

export default {
	name: 'ModerationQueuePage',
	components: { ConsultationReactionsTab },
}
</script>

<style scoped>
.moderation-queue {
	padding: 16px;
	max-width: 760px;
}

.moderation-queue__header {
	margin-bottom: 16px;
}
</style>
