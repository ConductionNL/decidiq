<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Conflicts of interest on this agenda item page (bod-10): the shared ConflictsTab.

 @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
-->
<template>
	<ConflictsTab :objectId="objectId" subjectType="agenda-item" />
</template>

<script>
import ConflictsTab from './ConflictsTab.vue'

export default {
	name: 'AgendaItemConflictsTab',
	components: { ConflictsTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
