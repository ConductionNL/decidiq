<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-3.4
-->
<template>
	<div>
		<h1>{{ t('decidesk', 'Bestuursorganen') }}</h1>
		<NcEmptyContent
			:name="t('decidesk', 'Governance Bodies')"
			:description="t('decidesk', 'Governance body management will be available in a future update.')">
			<template #icon>
				<DomainIcon :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'

export default {
	name: 'GovernanceBodyList',
	components: { NcEmptyContent, DomainIcon },
}
</script>
