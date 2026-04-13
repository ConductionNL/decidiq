<template>
	<CnDetailPage v-if="object"
		:title="object.orderNumber || ''"
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
import { useOrderStore } from '../../store/modules/order.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'OrderDetail',
	components: { CnDetailCard, CnDetailGrid, CnDetailPage, NcButton, NcLoadingIcon, DeleteIcon, PencilIcon },
	props: {
		entityId: { type: String, required: true },
	},
	data() {
		return { object: null }
	},
	computed: {
		isNew() { return this.entityId === 'new' },
		subtitle() { return this.object?.orderStatus || '' },
		detailData() {
			if (!this.object) return []
			return [
				{ label: t('decidesk', 'Order Number'), value: this.object.orderNumber },
				{ label: t('decidesk', 'Order Date'), value: this.object.orderDate },
				{ label: t('decidesk', 'Order Status'), value: this.object.orderStatus },
				{ label: t('decidesk', 'Total Price'), value: this.object.totalPrice },
				{ label: t('decidesk', 'Currency'), value: this.object.currency },
				{ label: t('decidesk', 'Delivery Date'), value: this.object.deliveryDate },
				{ label: t('decidesk', 'Payment Terms'), value: this.object.paymentTerms },
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
			const store = useOrderStore()
			const objects = await store.fetchObjects('order')
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
