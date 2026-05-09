<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: agenda items for a Meeting.

 TODO(decidesk-manifest-v1-tab): stub. Should fetch agenda-item objects
 filtered by `meeting === this.objectId`, sorted by orderNumber, and
 render via CnDataTable. Wire to useObjectStore('decidesk-objects').
-->
<template>
	<div class="decidesk-tab decidesk-tab--agenda">
		<CnNoteCard type="info" :title="t('decidesk', 'Agenda')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Agenda items for meeting {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'MeetingAgendaTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
