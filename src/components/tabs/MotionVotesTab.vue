<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: votes cast on a Motion.

 TODO(decidesk-manifest-v1-tab): stub. Should fetch vote objects through
 the motion's voting-round relation (motion → voting-round → votes) and
 render via CnDataTable. Live tallies belong in the LiveMeeting view, not
 here — this tab is the post-vote audit surface.
-->
<template>
	<div class="decidesk-tab decidesk-tab--votes">
		<CnNoteCard type="info" :title="t('decidesk', 'Votes')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Votes for motion {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'MotionVotesTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
