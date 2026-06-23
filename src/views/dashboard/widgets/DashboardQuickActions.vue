<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
 DashboardQuickActions — a compact row of primary quick-start buttons on the
 dashboard. Currently surfaces "Start decision-making process" (routes to the
 Decisions index, the entry point of the decision lifecycle). Add further
 quick actions here as needed.
-->
<template>
	<div class="decidesk-quick-actions">
		<NcButton type="primary" @click="startDecision">
			<template #icon>
				<Gavel :size="18" />
			</template>
			{{ t('decidesk', 'Start decision-making process') }}
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import Gavel from 'vue-material-design-icons/Gavel.vue'

export default {
	name: 'DashboardQuickActions',
	components: { NcButton, Gavel },
	methods: {
		/** Open the Decisions index — the start of a decision-making process. */
		startDecision() {
			this.$router.push({ name: 'Decisions' })
		},
	},
}
</script>

<style scoped>
.decidesk-quick-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: 8px 4px;
}
</style>
