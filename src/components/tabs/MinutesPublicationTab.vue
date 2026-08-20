<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Minutes detail-view publication tab — delegates to PublicationActionsTab with
 sourceType="minutes" (publish-decisions-via-opencatalogi).

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<PublicationActionsTab :objectId="objectId" sourceType="minutes" />
</template>

<script>
import PublicationActionsTab from './PublicationActionsTab.vue'

export default {
	name: 'MinutesPublicationTab',
	components: { PublicationActionsTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
