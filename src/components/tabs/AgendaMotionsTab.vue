<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: motions tied to an Agenda Item.

 TODO(decidesk-manifest-v1-tab): stub. Should fetch motion objects whose
 `agendaItem === this.objectId` and render via CnDataTable. The fully
 implemented version will likely reuse the existing `Motions.vue` filter
 helpers — port them out before deleting that file.
-->
<template>
	<div class="decidesk-tab decidesk-tab--motions">
		<CnNoteCard type="info" :title="t('decidesk', 'Motions')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Motions for agenda item {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'AgendaMotionsTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
