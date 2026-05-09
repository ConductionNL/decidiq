<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: signers / approvers on a Minutes record.

 TODO(decidesk-manifest-v1-tab): stub. Should render the `signers[]`
 array from the minutes object (each entry is a participant ref + a
 signedAt timestamp) plus pending-signature CTAs for the current user.
-->
<template>
	<div class="decidesk-tab decidesk-tab--signers">
		<CnNoteCard type="info" :title="t('decidesk', 'Signers')">
			{{ t('decidesk', 'Coming soon — this tab is currently a stub. Signers for minutes {id} will be listed here.', { id: objectId || '—' }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'MinutesSignersTab',
	components: { CnNoteCard },
	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},
}
</script>
