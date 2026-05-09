<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: amendments proposed against a Motion.

 TODO(decidesk-manifest-v1-tab): stub. Should fetch amendment objects
 whose `parentMotion === this.objectId` and render via CnDataTable, with
 a "Create amendment" action calling out to CnSchemaFormDialog.
-->
<template>
	<div class="decidesk-tab decidesk-tab--amendments">
		<CnNoteCard type="info" :title="t('decidesk', 'Amendments')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Amendments for motion {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'MotionAmendmentsTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
