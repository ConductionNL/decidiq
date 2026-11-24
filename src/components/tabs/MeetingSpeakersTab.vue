<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Public speakers on the meeting as a whole (inspreekrecht-plenair, REQ-INS-007):
 for a body whose policy level is the meeting, the approved speaking slots and
 pending requests render in the meeting block.

 @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
-->
<template>
	<SpeakingSlotsBlock data-testid="meeting-speakers" :meetingId="String(objectId || '')" place="" />
</template>

<script>
import SpeakingSlotsBlock from './SpeakingSlotsBlock.vue'

export default {
	name: 'MeetingSpeakersTab',
	components: { SpeakingSlotsBlock },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
