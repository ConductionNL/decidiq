<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: action items spawned by a Decision.

 TODO(decidesk-manifest-v1-tab): stub. Should fetch action-item objects
 whose `decision === this.objectId` and render via CnDataTable, with the
 same status pills as the standalone /action-items index.
-->
<template>
	<div class="decidesk-tab decidesk-tab--action-items">
		<CnNoteCard type="info" :title="t('decidesk', 'Action items')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Action items spawned by decision {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'DecisionActionItemsTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
