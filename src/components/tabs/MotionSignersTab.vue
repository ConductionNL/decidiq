<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Signers of a motion (a decision of decisionType motion): who signs, in which order, and Send for
 signature to the external signing service. The same widget as on minutes
 (MinutesSignersTab), pointed at this record.

 @spec openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
-->
<template>
	<MinutesSignersTab
		:objectId="objectId"
		schema="decision"
		subjectType="motion" />
</template>

<script>
import MinutesSignersTab from './MinutesSignersTab.vue'

export default {
	name: 'MotionSignersTab',
	components: { MinutesSignersTab },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
}
</script>
