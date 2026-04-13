<template>
	<CnDetailPage
		:entity-id="entityId"
		:detail-view="detailView"
		:title="entity?.title || t('decidesk', 'Besluit')"
		@back="$router.push({ name: 'Decisions' })">
		<template #header-actions>
			<NcButton type="secondary" @click="editMode = !editMode">
				{{ editMode ? t('decidesk', 'View') : t('decidesk', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="deleteEntity">
				{{ t('decidesk', 'Delete') }}
			</NcButton>
		</template>

		<template #content>
			<div class="decision-detail">
				<CnDetailCard :title="t('decidesk', 'Details')">
					<div class="detail-grid">
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Title') }}</span>
							<span class="detail-value">{{ entity?.title }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Text') }}</span>
							<span class="detail-value">{{ entity?.text }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Decision date') }}</span>
							<span class="detail-value">{{ formatDate(entity?.decisionDate) }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Outcome') }}</span>
							<span class="detail-value">
								<CnStatusBadge :label="entity?.outcome || ''" />
							</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Legal basis') }}</span>
							<span class="detail-value">{{ entity?.legalBasis || '-' }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Published') }}</span>
							<span class="detail-value">
								<template v-if="entity?.isPublished">
									{{ formatDate(entity.publishedAt) }}
								</template>
								<template v-else>
									{{ t('decidesk', 'Not published') }}
								</template>
							</span>
						</div>
					</div>

					<div class="publish-action">
						<NcButton
							v-if="entity?.outcome === 'adopted' && !entity?.isPublished"
							type="primary"
							@click="publishDecision">
							{{ t('decidesk', 'Publiceren') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Related Motion')">
					<p v-if="!relatedMotion" class="empty-content">
						{{ t('decidesk', 'No motion linked to this decision.') }}
					</p>
					<div v-else class="detail-row">
						<span class="detail-label">{{ t('decidesk', 'Motion') }}</span>
						<span class="detail-value">{{ relatedMotion.title }}</span>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Actiepunten')">
					<table v-if="relatedActionItems.length > 0" class="action-items-table">
						<thead>
							<tr>
								<th>{{ t('decidesk', 'Title') }}</th>
								<th>{{ t('decidesk', 'Assignee') }}</th>
								<th>{{ t('decidesk', 'Due date') }}</th>
								<th>{{ t('decidesk', 'Status') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="item in relatedActionItems"
								:key="item.id"
								class="clickable-row"
								@click="$router.push({ name: 'ActionItemDetail', params: { id: item.id } })">
								<td>{{ item.title }}</td>
								<td>{{ item.assignee || '-' }}</td>
								<td>{{ formatDate(item.dueDate) }}</td>
								<td>
									<CnStatusBadge :label="item.taskStatus || ''" />
								</td>
							</tr>
						</tbody>
					</table>
					<p v-else class="empty-content">
						{{ t('decidesk', 'No action items linked to this decision.') }}
					</p>
				</CnDetailCard>
			</div>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="entity?.id"
				:object-id="entity.id"
				:object-type="'decision'" />
		</template>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailCard, CnDetailPage, CnObjectSidebar, CnStatusBadge, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'DecisionDetail',
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
		const objectStore = useObjectStore()
		const detailView = useDetailView('decision', {
			objectStore,
		})
		return { detailView }
	},
	data() {
		return {
			editMode: false,
			relatedMotion: null,
			relatedActionItems: [],
		}
	},
	computed: {
		entity() {
			return this.detailView?.entity || null
		},
	},
	watch: {
		entity: {
			handler(val) {
				if (val) {
					this.loadRelated()
				}
			},
			immediate: true,
		},
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},
		async publishDecision() {
			try {
				const url = generateUrl(`/apps/decidesk/api/decisions/${this.entityId}/publish`)
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.detailView?.refresh?.()
				} else {
					const data = await response.json().catch(() => ({}))
					showError(t('decidesk', 'Failed to publish decision: ') + (data.message || response.statusText))
				}
			} catch (e) {
				showError(t('decidesk', 'Failed to publish decision: ') + e.message)
			}
		},
		async deleteEntity() {
			const objectStore = useObjectStore()
			await objectStore.deleteObject?.('decision', this.entityId)
			this.$router.push({ name: 'Decisions' })
		},
		async loadRelated() {
			const objectStore = useObjectStore()
			try {
				const actionItems = await objectStore.fetchObjects('actionItem', { decision: this.entityId })
				this.relatedActionItems = actionItems || []
			} catch (e) {
				this.relatedActionItems = []
			}

			// Fetch related motion from the entity's relations array.
			const motionRelation = (this.entity?.relations || []).find(
				(r) => r.schema?.toLowerCase() === 'motion',
			)
			if (motionRelation) {
				const motionId = motionRelation.objectId || motionRelation.id
				try {
					this.relatedMotion = await objectStore.fetchObject?.('motion', motionId) || null
				} catch (e) {
					this.relatedMotion = null
				}
			} else {
				this.relatedMotion = null
			}
		},
	},
}
</script>

<style scoped>
.decision-detail {
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

.publish-action {
	margin-top: 12px;
}

.action-items-table {
	width: 100%;
	border-collapse: collapse;
}

.action-items-table th,
.action-items-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.action-items-table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.clickable-row {
	cursor: pointer;
}

.clickable-row:hover {
	background-color: var(--color-background-hover);
}

.empty-content {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
