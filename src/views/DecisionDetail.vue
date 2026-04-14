<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-3.4
-->
<template>
	<div>
		<h1>{{ t('decidesk', 'Besluit') }}</h1>
		<p>{{ t('decidesk', 'Decision detail view — coming soon.') }}</p>
	</div>
</template>

<script>
export default {
	name: 'DecisionDetail',
	props: { id: { type: String, required: true } },
}
</script>
