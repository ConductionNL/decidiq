<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting "integrations" surface — demonstrates the pluggable integration
 registry (ADR-019). Mounts CnDetailPage with `sidebar.useRegistry: true`,
 which pushes the registry mode onto the shared `objectSidebarState`
 channel (see App.vue). The host CnObjectSidebar then renders one tab per
 registered integration provider: the built-in core tabs (Files / Notes /
 Tags / Tasks / Audit trail) plus the xWiki "Articles" leaf when the
 `openconnector` app is installed.

 Registered in src/customComponents.js as `MeetingIntegrations`; routed
 from the manifest at `/meetings/:id/integrations`.
-->
<template>
	<CnDetailPage
		:title="t('decidesk', 'Meeting integrations')"
		:description="t('decidesk', 'External integrations linked to this meeting — open the sidebar to browse linked articles, files, notes, tags, tasks and the audit trail.')"
		icon="PuzzleOutline"
		object-type="meeting"
		:object-id="id"
		:sidebar="sidebarConfig">
		<div class="meeting-integrations__body">
			<p>
				{{ t('decidesk', 'This page is backed by the pluggable integration registry. The "Articles" tab is provided by the xWiki integration via OpenConnector — linked wiki pages render with their breadcrumb and a text preview.') }}
			</p>
			<NcButton
				type="tertiary"
				:aria-label="t('decidesk', 'Back to meeting')"
				@click="$router.push({ name: 'MeetingDetail', params: { id } })">
				← {{ t('decidesk', 'Back to meeting') }}
			</NcButton>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'MeetingIntegrations',

	components: {
		CnDetailPage,
		NcButton,
	},

	props: {
		/**
		 * Meeting object UUID — captured from the `:id` route param by
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
		 * Sidebar config in the Object form (ADR-019). `useRegistry`
		 * flips CnObjectSidebar into registry mode; `register` / `schema`
		 * flow through `objectSidebarState` so the integration tabs can
		 * fetch the right object's sub-resources.
		 *
		 * @return {object} CnDetailPage `sidebar` prop value.
		 */
		sidebarConfig() {
			return {
				register: 'decidesk',
				schema: 'meeting',
				useRegistry: true,
			}
		},
	},
}
</script>

<style scoped>
.meeting-integrations__body {
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
