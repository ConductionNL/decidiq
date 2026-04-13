<template>
	<CnDetailPage v-if="object"
		:title="object.title || ''"
		:subtitle="subtitle"
		@edit="onEdit"
		@delete="onDelete">
		<template #actions>
			<NcButton type="primary" @click="onEdit">
				<template #icon>
					<PencilIcon :size="20" />
				</template>
				{{ t('decidesk', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="onDelete">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('decidesk', 'Delete') }}
			</NcButton>
		</template>
		<CnDetailCard :title="t('decidesk', 'Details')">
			<CnDetailGrid :data="detailData" />
		</CnDetailCard>
	</CnDetailPage>
	<NcLoadingIcon v-else :size="64" />
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnDetailCard, CnDetailGrid, CnDetailPage } from '@conduction/nextcloud-vue'
import { useMeetingStore } from '../../store/modules/meeting.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'MeetingDetail',
	components: { CnDetailCard, CnDetailGrid, CnDetailPage, NcButton, NcLoadingIcon, DeleteIcon, PencilIcon },
	props: {
		entityId: { type: String, required: true },
	},
	data() {
		return { object: null }
	},
	computed: {
		isNew() { return this.entityId === 'new' },
		subtitle() { return this.object?.meetingType || '' },
		detailData() {
			if (!this.object) return []
			return [
				{ label: t('decidesk', 'Title'), value: this.object.title },
				{ label: t('decidesk', 'Meeting Type'), value: this.object.meetingType },
				{ label: t('decidesk', 'Scheduled Date'), value: this.object.scheduledDate },
				{ label: t('decidesk', 'End Date'), value: this.object.endDate },
				{ label: t('decidesk', 'Location'), value: this.object.location },
				{ label: t('decidesk', 'Meeting Mode'), value: this.object.meetingMode },
				{ label: t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: t('decidesk', 'Quorum Required'), value: this.object.quorumRequired },
				{ label: t('decidesk', 'Series'), value: this.object.series },
			]
		},
	},
	async created() {
		if (!this.isNew) {
			await this.loadObject()
		}
	},
	methods: {
		async loadObject() {
			const store = useMeetingStore()
			const objects = await store.fetchObjects('meeting')
			this.object = objects.find(o => o.id === this.entityId || o.uuid === this.entityId) || null
		},
		onEdit() {
			// Edit handled by CnFormDialog in future spec
		},
		onDelete() {
			// Delete handled by CnDeleteDialog in future spec
		},
	},
}
</script>
