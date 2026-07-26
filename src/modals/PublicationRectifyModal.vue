<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Rectify-confirmation dialog for the public-publication flow
 (publish-decisions-via-opencatalogi). Rectification publishes a corrected new
 version and withdraws the old one in the same operation; published payloads are
 never edited in place.

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Rectify publication')"
		data-testid="publication-rectify-modal"
		@closing="$emit('close')">
		<template #default>
			<p>{{ t('decidesk', 'Rectification publishes a corrected new version and withdraws the current one in a single operation. The new version references the version it corrects.') }}</p>
			<NcTextArea
				v-model="reason"
				data-testid="publication-rectify-reason"
				:label="t('decidesk', 'Reason for the correction (optional)')"
				:placeholder="t('decidesk', 'e.g. Corrected vote totals')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="publication-rectify-confirm"
				@click="$emit('confirm', reason.trim())">
				{{ t('decidesk', 'Rectify') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'PublicationRectifyModal',
	components: { NcButton, NcDialog, NcTextArea },
	data() {
		return {
			reason: '',
		}
	},
}
</script>
