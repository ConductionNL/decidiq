<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: participants attending a Meeting.

 TODO(decidesk-manifest-v1-tab): stub. Should fetch participant objects
 linked to this meeting (likely via the meeting's `participants[]`
 cross-reference), and render via CnDataTable.
-->
<template>
	<div class="decidesk-tab decidesk-tab--participants">
		<CnNoteCard type="info" :title="t('decidesk', 'Participants')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Participants for meeting {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'MeetingParticipantsTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
