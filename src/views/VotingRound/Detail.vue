<template>
	<CnDetailPage v-if="object"
		:title="object.votingMethod || ''"
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
import { useVotingRoundStore } from '../../store/modules/votingRound.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'VotingRoundDetail',
	components: { CnDetailCard, CnDetailGrid, CnDetailPage, NcButton, NcLoadingIcon, DeleteIcon, PencilIcon },
	props: {
		entityId: { type: String, required: true },
	},
	data() {
		return { object: null }
	},
	computed: {
		isNew() { return this.entityId === 'new' },
		subtitle() { return this.object?.result || '' },
		detailData() {
			if (!this.object) return []
			return [
				{ label: t('decidesk', 'Voting Method'), value: this.object.votingMethod },
				{ label: t('decidesk', 'Is Secret'), value: this.object.isSecret },
				{ label: t('decidesk', 'Opened At'), value: this.object.openedAt },
				{ label: t('decidesk', 'Closed At'), value: this.object.closedAt },
				{ label: t('decidesk', 'Quorum Met'), value: this.object.quorumMet },
				{ label: t('decidesk', 'Result'), value: this.object.result },
				{ label: t('decidesk', 'Votes For'), value: this.object.votesFor },
				{ label: t('decidesk', 'Votes Against'), value: this.object.votesAgainst },
				{ label: t('decidesk', 'Votes Abstain'), value: this.object.votesAbstain },
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
			const store = useVotingRoundStore()
			const objects = await store.fetchObjects('votingRound')
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
