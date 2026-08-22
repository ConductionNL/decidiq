<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 DashboardEmptyState — welcome card shown when no governance body exists
 (REQ-005).

 The host dashboard renders this instead of the widget grid once the
 governance-body collection resolves to zero. It greets the user and offers
 three quick-start actions: Set Up Body → GovernanceBodies, Create Meeting →
 Meetings, Create Decision → Decisions.
-->
<template>
	<div class="dashboard-empty" data-testid="dashboard-empty-state">
		<NcEmptyContent
			:name="t('decidiq', 'Welcome to Decidiq!')"
			:description="
				t(
					'decidiq',
					'Welcome to Decidiq! Get started by setting up your first governing body.',
				)
			">
			<template #icon>
				<AccountGroupOutline :size="48" />
			</template>
			<template #action>
				<div class="dashboard-empty__actions">
					<NcButton
						variant="primary"
						data-testid="dashboard-empty-setup"
						@click="goGovernanceBodies">
						{{ t('decidiq', 'Set Up Body') }}
					</NcButton>
					<NcButton
						data-testid="dashboard-empty-meeting"
						@click="goMeetings">
						{{ t('decidiq', 'Create Meeting') }}
					</NcButton>
					<NcButton
						data-testid="dashboard-empty-decision"
						@click="goDecisions">
						{{ t('decidiq', 'Create Decision') }}
					</NcButton>
				</div>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'

export default {
	name: 'DashboardEmptyState',

	components: { NcButton, NcEmptyContent, AccountGroupOutline },

	methods: {
		/**
		 * Navigate to Governance bodies, where the first governing body is created.
		 *
		 * This used to push `{ name: 'Settings' }` — the in-app `type:"settings"`
		 * page deleted under ADR-079 D1. That target was wrong on both counts:
		 * the route no longer resolves (vue-router warns and the click does
		 * nothing), and a governing body was never created there in the first
		 * place. Bodies are created on the GovernanceBodies index, which is what
		 * the button's own label — "Set Up Body" — has always promised.
		 *
		 * @return {void}
		 * @spec openspec/specs/app-navigation/spec.md#scenario-organisation-item-is-present-and-routes-to-governancebodies
		 */
		goGovernanceBodies() {
			this.$router.push({ name: 'GovernanceBodies' })
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
