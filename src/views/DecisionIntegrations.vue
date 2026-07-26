<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Decision "integrations" surface — consumes the pluggable integration
 registry (ADR-019) bound to the decision-dossier OR object. Mounts
 CnDetailPage with `sidebar.useRegistry: true`, which pushes registry
 mode onto the shared `objectSidebarState` channel (see App.vue). The
 host CnObjectSidebar then renders one tab per registered integration
 provider: the built-in core tabs (Files / Notes / Tags / Tasks / Audit
 trail) plus — when the Email integration leaf is registered (ADR-022
 migrate-email-links-to-email-leaf) — an "Email" tab that links emails
 to this dossier through the leaf rather than an in-app EmailLink store.

 Graceful degradation: when Mail (or the email leaf) is absent the
 registry simply omits the Email tab; the dossier stays usable.

 Registered in src/registry.js as `DecisionIntegrations`; routed from
 the manifest at `/decisions/:id/integrations`.
-->
<template>
	<CnDetailPage
		:title="t('decidesk', 'Decision integrations')"
		:description="t('decidesk', 'External integrations linked to this decision dossier — open the sidebar to link emails, browse files, notes, tags, tasks and the audit trail.')"
		icon="PuzzleOutline"
		object-type="decision"
		:object-id="id"
		:sidebar="sidebarConfig">
		<div class="decision-integrations__body" data-testid="decision-integrations">
			<p>
				{{ t('decidesk', 'This page is backed by the pluggable integration registry. When the Email integration is installed, an "Email" tab lets you link emails to this decision dossier — the link is held by the registry, not an in-app email-link store.') }}
			</p>
			<NcButton
				variant="tertiary"
				data-testid="decision-integrations-back"
				:aria-label="t('decidesk', 'Back to decision')"
				@click="$router.push({ name: 'DecisionDetail', params: { id } })">
				← {{ t('decidesk', 'Back to decision') }}
			</NcButton>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'DecisionIntegrations',

	components: {
		CnDetailPage,
		NcButton,
	},

	props: {
		/**
		 * Decision object UUID — captured from the `:id` route param by
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
		 * a tab bound to this decision object.
		 *
		 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-1.2
		 * @return {object} CnDetailPage `sidebar` prop value.
		 */
		sidebarConfig() {
			return {
				register: 'decidesk',
				schema: 'decision',
				useRegistry: true,
			}
		},
	},
}
</script>

<style scoped>
.decision-integrations__body {
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
