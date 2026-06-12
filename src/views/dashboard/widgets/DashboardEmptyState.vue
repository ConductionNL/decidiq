<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 DashboardEmptyState — welcome card shown when no governance body exists
 (REQ-005).

 The host dashboard renders this instead of the widget grid once the
 governance-body collection resolves to zero. It greets the user and offers
 three quick-start actions: Set Up Body → Settings, Create Meeting → Meetings,
 Create Decision → Decisions.
-->
<template>
	<div class="dashboard-empty" data-testid="dashboard-empty-state">
		<NcEmptyContent
			:name="t('decidesk', 'Welcome to Decidesk!')"
			:description="t('decidesk', 'Welcome to Decidesk! Get started by setting up your first governing body in Settings.')">
			<template #icon>
				<AccountGroupOutline :size="48" />
			</template>
			<template #action>
				<div class="dashboard-empty__actions">
					<NcButton type="primary" data-testid="dashboard-empty-setup" @click="goSettings">
						{{ t('decidesk', 'Set Up Body') }}
					</NcButton>
					<NcButton data-testid="dashboard-empty-meeting" @click="goMeetings">
						{{ t('decidesk', 'Create Meeting') }}
					</NcButton>
					<NcButton data-testid="dashboard-empty-decision" @click="goDecisions">
						{{ t('decidesk', 'Create Decision') }}
					</NcButton>
				</div>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'

export default {
	name: 'DashboardEmptyState',

	components: { NcButton, NcEmptyContent, AccountGroupOutline },

	methods: {
		/**
		 * Navigate to the Settings page to set up the first governing body.
		 *
		 * @return {void}
		 */
		goSettings() {
			this.$router.push({ name: 'Settings' })
		},

		/**
		 * Navigate to the Meetings view.
		 *
		 * @return {void}
		 */
		goMeetings() {
			this.$router.push({ name: 'Meetings' })
		},

		/**
		 * Navigate to the Decisions view.
		 *
		 * @return {void}
		 */
		goDecisions() {
			this.$router.push({ name: 'Decisions' })
		},
	},
}
</script>

<style scoped>
.dashboard-empty {
	padding: 24px;
}

.dashboard-empty__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: center;
}
</style>
