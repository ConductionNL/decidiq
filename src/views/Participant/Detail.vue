<template>
	<CnDetailPage v-if="object"
		:title="object.displayName || ''"
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
import { useParticipantStore } from '../../store/modules/participant.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'ParticipantDetail',
	components: { CnDetailCard, CnDetailGrid, CnDetailPage, NcButton, NcLoadingIcon, DeleteIcon, PencilIcon },
	props: {
		entityId: { type: String, required: true },
	},
	data() {
		return { object: null }
	},
	computed: {
		isNew() { return this.entityId === 'new' },
		subtitle() { return this.object?.role || '' },
		detailData() {
			if (!this.object) return []
			return [
				{ label: t('decidesk', 'Display Name'), value: this.object.displayName },
				{ label: t('decidesk', 'Role'), value: this.object.role },
				{ label: t('decidesk', 'Party'), value: this.object.party },
				{ label: t('decidesk', 'Email'), value: this.object.email },
				{ label: t('decidesk', 'Joined At'), value: this.object.joinedAt },
				{ label: t('decidesk', 'Left At'), value: this.object.leftAt },
				{ label: t('decidesk', 'Voting Weight'), value: this.object.votingWeight },
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
			const store = useParticipantStore()
			const objects = await store.fetchObjects('participant')
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
