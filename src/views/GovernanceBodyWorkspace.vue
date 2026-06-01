<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body workspace tab — surfaces the ADR-019 collectives leaf as the
 faction/committee collaboration workspace. Uses CnDetailPage with
 `sidebar.useRegistry: true` so the OR integration registry drives which
 integration leaves (Collectives, xWiki, etc.) are rendered as tabs.

 When the Nextcloud Collectives app is not installed the leaf tab is simply
 absent from the sidebar; no error is raised (graceful degradation per D1 /
 REQ-WS-COLL-001 scenario 2).

 Routed from the manifest at `/governance-bodies/:id/workspace`.
 Registered as `GovernanceBodyWorkspace` in src/registry.js.
-->
<template>
	<CnDetailPage
		:title="t('decidesk', 'Workspace')"
		:description="t('decidesk', 'Collaboration workspace for this governance body — open the sidebar to access the shared Collective, files, notes, and audit trail.')"
		icon="AccountGroupOutline"
		object-type="governance-body"
		:object-id="id"
		:sidebar="sidebarConfig">
		<div class="governance-body-workspace__body" data-testid="governance-body-workspace">
			<p>
				{{ t('decidesk', 'The workspace for this governance body or faction is powered by the Nextcloud Collectives integration. Open the sidebar to access the shared wiki pages and collaborative documents.') }}
			</p>
			<NcButton
				type="tertiary"
				data-testid="governance-body-workspace-back"
				:aria-label="t('decidesk', 'Back to governance body')"
				@click="$router.push({ name: 'GovernanceBodyDetail', params: { id } })">
				← {{ t('decidesk', 'Back to governance body') }}
			</NcButton>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'GovernanceBodyWorkspace',

	components: {
		CnDetailPage,
		NcButton,
	},

	props: {
		/**
		 * Governance body object UUID — captured from the `:id` route param by
		 * CnPageRenderer (custom pages receive `$route.params` as props).
		 *
		 * @type {string}
		 */
		id: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * Sidebar config that activates registry mode for the governance-body
		 * schema. OR's integration registry renders registered leaves (including
		 * the collectives leaf when the Collectives app is installed) as sidebar
		 * tabs. The tab is absent when the app is not installed — no error.
		 *
		 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-1.2
		 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-1.4
		 * @return {object} CnDetailPage `sidebar` prop value.
		 */
		sidebarConfig() {
			return {
				register: 'decidesk',
				schema: 'governance-body',
				useRegistry: true,
			}
		},
	},
}
</script>

<style scoped>
.governance-body-workspace__body {
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
