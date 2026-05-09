<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: members of a Governance Body.

 TODO(decidesk-manifest-v1-tab): stub. Needs to fetch participants whose
 governanceBody === this.objectId and render them via CnDataTable
 (or CnCardGrid). Wire through useObjectStore('decidesk-objects')
 and the participant schema in lib/Settings/decidesk_register.json.
 Until implemented this tab renders a placeholder note so the tab still
 mounts cleanly under the manifest-driven CnObjectSidebar.
-->
<template>
	<div class="decidesk-tab decidesk-tab--members">
		<CnNoteCard type="info" :title="t('decidesk', 'Members')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Members of governance body {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'GovernanceBodyMembersTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
