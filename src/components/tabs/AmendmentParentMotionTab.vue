<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: parent Motion of an Amendment (cross-schema lookup).

 TODO(decidesk-manifest-v1-tab): stub. Should resolve `parentMotion` on
 the current amendment object, fetch the motion, and render its summary
 (title, proposer, lifecycle) plus a "View full motion" link to
 /motions/:parentMotionId. Cross-schema fetch lives inside this component
 (per the abstract-sidebar contract — the renderer doesn't traverse
 references for us).

 @spec openspec/changes/decidesk-manifest-v1/design.md (open question 3)
-->
<template>
	<div class="decidesk-tab decidesk-tab--parent-motion">
		<CnNoteCard type="info" :title="t('decidesk', 'Parent motion')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Parent motion of amendment {id} will be summarised here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'AmendmentParentMotionTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
