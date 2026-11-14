<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Themes on the agenda item detail page — delegates to ThemeLinkTab with
 kind="agendaItem" (publication-theme-pages, REQ-THP-001).

 @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
-->
<template>
	<ThemeLinkTab :objectId="objectId" kind="agendaItem" />
</template>

<script>
import ThemeLinkTab from './ThemeLinkTab.vue'

export default {
	name: 'AgendaItemThemesTab',
	components: { ThemeLinkTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
