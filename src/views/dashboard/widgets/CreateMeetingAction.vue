<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcButton type="primary" :small="true" @click="onClick">
		<template #icon>
			<Plus :size="16" />
		</template>
		{{ t('decidesk', 'Create meeting') }}
	</NcButton>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
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
