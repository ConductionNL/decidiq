<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Agenda detail-view publication tab — delegates to PublicationActionsTab with
 sourceType="agenda" (publish-decisions-via-opencatalogi), and
 with sourceType="activity" for the residents' calendar.

 @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<div>
		<PublicationActionsTab :objectId="objectId" sourceType="agenda" />
		<!-- planning-activity-calendar-by-audience (REQ-ACAL-004): a public
		     meeting goes on the residents' calendar without a formal agenda. -->
		<PublicationActionsTab
			:objectId="objectId"
			sourceType="activity"
			:title="t('decidiq', 'Public calendar')"
			:publishLabel="t('decidiq', 'Publish to the public calendar')"
			data-testid="activity-publication" />
	</div>
</template>

<script>
import PublicationActionsTab from './PublicationActionsTab.vue'

export default {
	name: 'AgendaPublicationTab',
	components: { PublicationActionsTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
