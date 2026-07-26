<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: confirm adopting all consent agenda items (hamerstukken).

 Extracted from LiveMeeting per the modal-isolation rule (ADR-004):
 the dialog only confirms; the parent performs the adoption on @confirm.

 @spec openspec/specs/agenda-management/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Confirm adoption')"
		data-testid="adopt-consent-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>{{ t('decidesk', 'This will set all {n} consent agenda items to "Adopted" (afgerond). Continue?', { n: count }) }}</p>
		</template>
		<template #actions>
			<NcButton variant="primary" :loading="processing" @click="$emit('confirm')">
				{{ t('decidesk', 'Confirm') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'AdoptConsentAgendaDialog',

	components: { NcButton, NcDialog },

	props: {
		/** Number of consent agenda items that will be adopted */
		count: { type: Number, default: 0 },
		/** Whether the adoption is in progress (parent-driven) */
		processing: { type: Boolean, default: false },
	},

	emits: ['confirm', 'close'],
}
</script>
