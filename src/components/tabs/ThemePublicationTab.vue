<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Theme detail-view publication tab (publication-theme-pages, REQ-THP-003) —
 delegates to PublicationActionsTab with sourceType="theme". The server
 refuses a theme that is not public or has no published item.

 @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-003-a-published-theme-carries-only-published-items
-->
<template>
	<PublicationActionsTab :objectId="objectId" sourceType="theme" />
</template>

<script>
import PublicationActionsTab from './PublicationActionsTab.vue'

export default {
	name: 'ThemePublicationTab',
	components: { PublicationActionsTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
