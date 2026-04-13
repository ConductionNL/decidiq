<template>
	<CnDetailPage v-if="object"
		:title="object.name || ''"
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
import { useGovernanceBodyStore } from '../../store/modules/governanceBody.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'GovernanceBodyDetail',
	components: { CnDetailCard, CnDetailGrid, CnDetailPage, NcButton, NcLoadingIcon, DeleteIcon, PencilIcon },
	props: {
		entityId: { type: String, required: true },
	},
	data() {
		return { object: null }
	},
	computed: {
		isNew() { return this.entityId === 'new' },
		subtitle() { return this.object?.bodyType || '' },
		detailData() {
			if (!this.object) return []
			return [
				{ label: t('decidesk', 'Name'), value: this.object.name },
				{ label: t('decidesk', 'Body Type'), value: this.object.bodyType },
				{ label: t('decidesk', 'Domain'), value: this.object.domain },
				{ label: t('decidesk', 'Workflow Template'), value: this.object.workflowTemplate },
				{ label: t('decidesk', 'Quorum Rule'), value: this.object.quorumRule },
				{ label: t('decidesk', 'Voting Default'), value: this.object.votingDefault },
				{ label: t('decidesk', 'Term Start'), value: this.object.termStart },
				{ label: t('decidesk', 'Term End'), value: this.object.termEnd },
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
			const store = useGovernanceBodyStore()
			const objects = await store.fetchObjects('governanceBody')
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
