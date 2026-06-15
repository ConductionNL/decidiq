<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Minutes detail-view publication tab — delegates to PublicationActionsTab with
 sourceType="minutes" (publish-decisions-via-opencatalogi).

 @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
-->
<template>
	<PublicationActionsTab :object-id="objectId" source-type="minutes" />
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
