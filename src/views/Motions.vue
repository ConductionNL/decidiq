<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion index view — lists all motions with lifecycle and type filtering.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.1
-->
<template>
	<CnIndexPage
		object-type="motion"
		:columns="columns"
		:object-store="objectStore"
		:sidebar-state="sidebarState"
		:new-route="{ name: 'MotionDetail', params: { id: 'new' } }"
		@row-click="onRowClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'
import { inject } from 'vue'

export default {
	name: 'Motions',
	components: { CnIndexPage },
	setup() {
		const objectStore = useObjectStore()
		const sidebarState = inject('sidebarState', null)
		const listView = useListView('motion', { sidebarState, objectStore })
		return { ...listView, objectStore, sidebarState }
	},
	computed: {
		columns() {
			return [
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'motionType', label: this.t('decidesk', 'Type'), type: 'badge' },
				{ key: 'proposer', label: this.t('decidesk', 'Proposer') },
				{ key: 'lifecycle', label: this.t('decidesk', 'Lifecycle'), type: 'badge' },
				{ key: 'submittedAt', label: this.t('decidesk', 'Submitted'), type: 'datetime' },
			]
		},
	},
	methods: {
		onRowClick(motion) {
			this.$router.push({ name: 'MotionDetail', params: { id: motion.id || motion.uuid } })
		},
	},
}
</script>
