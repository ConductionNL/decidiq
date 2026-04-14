<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-3.4
-->
<template>
	<div>
		<h1>{{ t('decidesk', 'Moties') }}</h1>
		<NcEmptyContent
			:name="t('decidesk', 'Motions')"
			:description="t('decidesk', 'Motion management will be available in a future update.')">
			<template #icon>
				<FileDocumentOutline :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

export default {
	name: 'MotionList',
	components: { NcEmptyContent, FileDocumentOutline },
}
</script>
