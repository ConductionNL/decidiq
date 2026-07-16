<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Decision detail-view publication tab — delegates to PublicationActionsTab with
 sourceType="decision" (publish-decisions-via-opencatalogi).

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<PublicationActionsTab :object-id="objectId" source-type="decision" />
</template>

<script>
import PublicationActionsTab from './PublicationActionsTab.vue'

export default {
	name: 'DecisionPublicationTab',
	components: { PublicationActionsTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
