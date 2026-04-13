<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-3.4
-->
<template>
	<div>
		<h1>{{ t('decidesk', 'Deelnemers') }}</h1>
		<NcEmptyContent
			:name="t('decidesk', 'Participants')"
			:description="t('decidesk', 'Participant management will be available in a future update.')">
			<template #icon>
				<AccountGroupOutline :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'

export default {
	name: 'ParticipantList',
	components: { NcEmptyContent, AccountGroupOutline },
}
</script>
