<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcButton variant="primary" :small="true" @click="onClick">
		<template #icon>
			<Plus :size="16" />
		</template>
		{{ t('decidiq', 'Create meeting') }}
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'CreateMeetingAction',
	components: { NcButton, Plus },
	methods: {
		onClick() {
			this.$router.push({ name: 'Meetings' })
		},
	},
}
</script>
