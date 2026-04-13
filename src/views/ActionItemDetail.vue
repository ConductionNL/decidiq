<template>
	<CnDetailPage
		:entity-id="entityId"
		:detail-view="detailView"
		:title="entity?.title || t('decidesk', 'Actiepunt')"
		@back="$router.push({ name: 'ActionItems' })">
		<template #header-actions>
			<NcButton type="secondary" @click="editMode = !editMode">
				{{ editMode ? t('decidesk', 'View') : t('decidesk', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="deleteEntity">
				{{ t('decidesk', 'Delete') }}
			</NcButton>
		</template>

		<template #content>
			<div class="action-item-detail">
				<CnDetailCard :title="t('decidesk', 'Details')">
					<div class="detail-grid">
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Title') }}</span>
							<span class="detail-value">{{ entity?.title }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Description') }}</span>
							<span class="detail-value">{{ entity?.description || '-' }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Assignee') }}</span>
							<span class="detail-value">{{ entity?.assignee || '-' }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Due date') }}</span>
							<span class="detail-value">{{ formatDate(entity?.dueDate) }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Status') }}</span>
							<span class="detail-value">
								<CnStatusBadge
									:label="getStatusLabel()"
									:variant="getStatusVariant()" />
							</span>
						</div>
						<div v-if="entity?.completedAt" class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Completed at') }}</span>
							<span class="detail-value">{{ formatDate(entity.completedAt) }}</span>
						</div>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Status update')">
					<div class="status-actions">
						<NcButton
							v-if="entity?.taskStatus === 'open'"
							type="primary"
							@click="updateStatus('in-progress')">
							{{ t('decidesk', 'In behandeling') }}
						</NcButton>
						<NcButton
							v-if="entity?.taskStatus === 'in-progress'"
							type="primary"
							@click="updateStatus('completed')">
							{{ t('decidesk', 'Afgerond') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Related Decision')">
					<p class="empty-content">
						{{ t('decidesk', 'Related decisions are shown via OpenRegister relations.') }}
					</p>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Related Meeting')">
					<p class="empty-content">
						{{ t('decidesk', 'Related meetings are shown via OpenRegister relations.') }}
					</p>
				</CnDetailCard>
			</div>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="entity?.id"
				:object-id="entity.id"
				:object-type="'action-item'" />
		</template>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailCard, CnDetailPage, CnObjectSidebar, CnStatusBadge, useDetailView } from '@conduction/nextcloud-vue'
import { useActionItemStore } from '../store/modules/actionItem.js'

export default {
	name: 'ActionItemDetail',
	components: {
		CnDetailCard,
		CnDetailPage,
		CnObjectSidebar,
		CnStatusBadge,
		NcButton,
	},
	props: {
		entityId: {
			type: String,
			required: true,
		},
	},
	setup() {
		const actionItemStore = useActionItemStore()
		const detailView = useDetailView('action-item', {
			objectStore: actionItemStore,
		})
		return { detailView }
	},
	data() {
		return {
			editMode: false,
		}
	},
	computed: {
		entity() {
			return this.detailView?.entity || null
		},
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},
		isOverdue() {
			if (!this.entity) return false
			if (this.entity.taskStatus === 'completed') return false
			if (!this.entity.dueDate) return false
			return new Date(this.entity.dueDate) < new Date()
		},
		getStatusLabel() {
			if (this.isOverdue() && this.entity?.taskStatus !== 'overdue') {
				return this.t('decidesk', 'Overdue')
			}
			return this.entity?.taskStatus || ''
		},
		getStatusVariant() {
			if (this.isOverdue() || this.entity?.taskStatus === 'overdue') {
				return 'error'
			}
			if (this.entity?.taskStatus === 'completed') return 'success'
			if (this.entity?.taskStatus === 'in-progress') return 'warning'
			return 'default'
		},
		async updateStatus(newStatus) {
			const actionItemStore = useActionItemStore()
			const updated = { ...this.entity, taskStatus: newStatus }

			if (newStatus === 'completed') {
				updated.completedAt = new Date().toISOString()
			}

			await actionItemStore.saveObject?.('action-item', updated)
			this.detailView?.refresh?.()
		},
		async deleteEntity() {
			const actionItemStore = useActionItemStore()
			await actionItemStore.deleteObject?.('action-item', this.entityId)
			this.$router.push({ name: 'ActionItems' })
		},
	},
}
</script>

<style scoped>
.action-item-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.detail-grid {
	display: grid;
	gap: 8px;
}

.detail-row {
	display: flex;
	gap: 16px;
	padding: 4px 0;
}

.detail-label {
	font-weight: 600;
	min-width: 120px;
	color: var(--color-text-maxcontrast);
}

.status-actions {
	display: flex;
	gap: 8px;
}

.empty-content {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
