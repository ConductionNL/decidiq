<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-7.2
-->
<template>
	<div class="decidesk-detail">
		<div v-if="detail.loading.value" class="decidesk-detail__loading">
			<NcLoadingIcon :size="64" />
		</div>

		<template v-else-if="detail.isNew.value">
			<CnFormDialog
				:schema="schema"
				@confirm="detail.onSave"
				@close="goBack" />
		</template>

		<template v-else>
			<CnDetailPage
				:object="detail.object.value"
				:schema="schema"
				:loading="detail.loading.value"
				:editing="detail.editing.value"
				@save="detail.onSave"
				@cancel="detail.editing.value = false">
				<template #header-actions>
					<NcButton type="secondary" @click="detail.editing.value = true">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('decidesk', 'Edit') }}
					</NcButton>
					<NcButton type="error" @click="detail.showDeleteDialog.value = true">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('decidesk', 'Delete') }}
					</NcButton>
				</template>

				<template #sections>
					<CnDetailCard :title="t('decidesk', 'Agenda Items')">
						<p v-if="!relatedAgendaItems.length">
							{{ t('decidesk', 'No agenda items linked to this meeting.') }}
						</p>
						<ul v-else>
							<li v-for="item in relatedAgendaItems" :key="item.id">
								<router-link :to="{ name: 'AgendaItemDetail', params: { id: item.id } }">
									{{ item.orderNumber }}. {{ item.title }}
								</router-link>
							</li>
						</ul>
					</CnDetailCard>
				</template>

				<template #sidebar>
					<CnObjectSidebar
						:object="detail.object.value"
						:schema="schema"
						object-type="meeting"
						:store="objectStore" />
				</template>
			</CnDetailPage>

			<CnDeleteDialog
				v-if="detail.showDeleteDialog.value"
				:item="detail.object.value"
				name-field="title"
				@confirm="onDeleteConfirm"
				@close="detail.showDeleteDialog.value = false" />
		</template>
	</div>
</template>

<script>
import { ref, computed } from 'vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDeleteDialog, CnFormDialog, CnObjectSidebar, useDetailView } from '@conduction/nextcloud-vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { useObjectStore } from '../store/modules/object.js'

/**
 * Meeting detail view with related AgendaItems and edit/delete actions.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-7.2
 */
export default {
	name: 'MeetingDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		CnDetailPage,
		CnDetailCard,
		CnDeleteDialog,
		CnFormDialog,
		CnObjectSidebar,
		PencilIcon,
		TrashCanOutline,
	},

	props: {
		id: { type: String, required: true },
	},

	setup(props) {
		const objectStore = useObjectStore()
		const detail = useDetailView('meeting', ref(props.id), {
			objectStore: useObjectStore,
			router: null,
		})
		const schema = computed(() => objectStore.getSchema('meeting'))

		return { detail, objectStore, schema }
	},

	computed: {
		relatedAgendaItems() {
			const obj = this.detail.object.value
			if (!obj?.relations) return []
			return (obj.relations || [])
				.filter((r) => r.schema === 'agenda-item')
				.sort((a, b) => (a.orderNumber || 0) - (b.orderNumber || 0))
		},
	},

	methods: {
		goBack() {
			this.$router.push({ name: 'MeetingList' })
		},
		async onDeleteConfirm() {
			const success = await this.detail.confirmDelete()
			if (success) {
				this.$router.push({ name: 'MeetingList' })
			}
		},
	},
}
</script>

<style scoped>
.decidesk-detail__loading {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
}
</style>
