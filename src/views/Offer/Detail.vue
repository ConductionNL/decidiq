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
import { useOfferStore } from '../../store/modules/offer.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'OfferDetail',
	components: { CnDetailCard, CnDetailGrid, CnDetailPage, NcButton, NcLoadingIcon, DeleteIcon, PencilIcon },
	props: {
		entityId: { type: String, required: true },
	},
	data() {
		return { object: null }
	},
	computed: {
		isNew() { return this.entityId === 'new' },
		subtitle() { return this.object?.availability || '' },
		detailData() {
			if (!this.object) return []
			return [
				{ label: t('decidesk', 'Name'), value: this.object.name },
				{ label: t('decidesk', 'Price'), value: this.object.price },
				{ label: t('decidesk', 'Price Currency'), value: this.object.priceCurrency },
				{ label: t('decidesk', 'Valid From'), value: this.object.validFrom },
				{ label: t('decidesk', 'Valid Through'), value: this.object.validThrough },
				{ label: t('decidesk', 'Availability'), value: this.object.availability },
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
			const store = useOfferStore()
			const objects = await store.fetchObjects('offer')
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
