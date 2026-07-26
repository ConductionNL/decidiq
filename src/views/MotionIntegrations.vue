<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion "integrations" surface — pluggable integration registry (ADR-019).
 Mounts CnDetailPage with `sidebar.useRegistry: true`, which pushes the registry
 mode onto the shared `objectSidebarState` channel. The host CnObjectSidebar then
 renders one tab per registered integration provider, including the Talk leaf for
 discussion (migrate-comments-to-talk-leaf, ADR-022).

 If the Talk app is absent the registry hides the Talk tab automatically —
 graceful degradation requires no additional code here (ADR-019 stage-1 filter).

 Registered in src/manifest.json at `/motions/:id/integrations`.

 @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-1.3
-->
<template>
	<CnDetailPage
		:title="t('decidesk', 'Motion integrations')"
		:description="t('decidesk', 'External integrations linked to this motion — open the sidebar to browse the Discussion (Talk), files, notes, tags, tasks and the audit trail.')"
		icon="PuzzleOutline"
		object-type="motion"
		:object-id="id"
		:sidebar="sidebarConfig">
		<div class="motion-integrations__body" data-testid="motion-integrations">
			<p>
				{{ t('decidesk', 'This page is backed by the pluggable integration registry. The Discussion tab is provided by the Talk integration leaf — messages posted there are linked to this motion object and visible to all participants.') }}
			</p>
			<NcButton
				variant="tertiary"
				data-testid="motion-integrations-back"
				:aria-label="t('decidesk', 'Back to motion')"
				@click="$router.push({ name: 'MotionDetail', params: { id } })">
				← {{ t('decidesk', 'Back to motion') }}
			</NcButton>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'MotionIntegrations',

	components: {
		CnDetailPage,
		NcButton,
	},

	props: {
		/**
		 * Motion object UUID — captured from the `:id` route param.
		 *
		 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-1.3
		 * @type {string}
		 */
		id: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * Sidebar config in the Object form (ADR-019). `useRegistry` flips
		 * CnObjectSidebar into registry mode; `register` / `schema` flow through
		 * `objectSidebarState` so the Talk leaf fetches the correct object's
		 * conversation. The Talk tab is hidden automatically when Talk is absent
		 * (ADR-019 stage-1 filter — graceful degradation).
		 *
		 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-1.3
		 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-1.4
		 * @return {object} CnDetailPage `sidebar` prop value.
		 */
		sidebarConfig() {
			return {
				register: 'decidesk',
				schema: 'motion',
				useRegistry: true,
			}
		},
	},
}
</script>

<style scoped>
.motion-integrations__body {
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
