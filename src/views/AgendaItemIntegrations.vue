<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Agenda-item "integrations" surface — consumes the pluggable integration
 registry (ADR-019) bound to the agenda-item OR object. Mounts
 CnDetailPage with `sidebar.useRegistry: true`, which pushes registry
 mode onto the shared `objectSidebarState` channel (see App.vue). The
 host CnObjectSidebar then renders one tab per registered integration
 provider, including the Email integration leaf (ADR-022
 migrate-email-links-to-email-leaf) where email linking previously
 applied. Linking an email to this agenda item is done through the leaf,
 not the retired in-app EmailLink store.

 Graceful degradation: when Mail (or the email leaf) is absent the
 registry simply omits the Email tab; the agenda item stays usable.

 Registered in src/registry.js as `AgendaItemIntegrations`; routed from
 the manifest at `/agenda-items/:id/integrations`.
-->
<template>
	<CnDetailPage
		:title="t('decidesk', 'Agenda item integrations')"
		:description="t('decidesk', 'External integrations linked to this agenda item — open the sidebar to link emails, browse files, notes, tags, tasks and the audit trail.')"
		icon="PuzzleOutline"
		object-type="agenda-item"
		:object-id="id"
		:sidebar="sidebarConfig">
		<div class="agenda-item-integrations__body" data-testid="agenda-item-integrations">
			<p>
				{{ t('decidesk', 'This page is backed by the pluggable integration registry. When the Email integration is installed, an "Email" tab lets you link emails to this agenda item — the link is held by the registry, not an in-app email-link store.') }}
			</p>
			<NcButton
				variant="tertiary"
				data-testid="agenda-item-integrations-back"
				:aria-label="t('decidesk', 'Back to agenda item')"
				@click="$router.push({ name: 'AgendaItemDetail', params: { id } })">
				← {{ t('decidesk', 'Back to agenda item') }}
			</NcButton>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'AgendaItemIntegrations',

	components: {
		CnDetailPage,
		NcButton,
	},

	props: {
		/**
		 * Agenda-item object UUID — captured from the `:id` route param by
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
		 * flips CnObjectSidebar into registry mode so every registered
		 * integration provider — including the Email leaf — surfaces as
		 * a tab bound to this agenda-item object.
		 *
		 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-1.3
		 * @return {object} CnDetailPage `sidebar` prop value.
		 */
		sidebarConfig() {
			return {
				register: 'decidesk',
				schema: 'agenda-item',
				useRegistry: true,
			}
		},
	},
}
</script>

<style scoped>
.agenda-item-integrations__body {
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
