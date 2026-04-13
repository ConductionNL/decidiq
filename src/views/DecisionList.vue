<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-3.4
-->
<template>
	<div>
		<h1>{{ t('decidesk', 'Besluiten') }}</h1>
		<NcEmptyContent
			:name="t('decidesk', 'Decisions')"
			:description="t('decidesk', 'Decision management will be available in a future update.')">
			<template #icon>
				<GavelIcon :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'

export default {
	name: 'DecisionList',
	components: { NcEmptyContent, GavelIcon },
}
</script>
