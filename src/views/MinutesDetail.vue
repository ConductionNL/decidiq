<template>
	<CnDetailPage
		:entity-id="entityId"
		:detail-view="detailView"
		:title="entity?.title || t('decidesk', 'Notulen')"
		@back="$router.push({ name: 'Minutes' })">
		<template #header-actions>
			<NcButton type="secondary" @click="editMode = !editMode">
				{{ editMode ? t('decidesk', 'View') : t('decidesk', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="deleteEntity">
				{{ t('decidesk', 'Delete') }}
			</NcButton>
		</template>

		<template #content>
			<div class="minutes-detail">
				<CnDetailCard :title="t('decidesk', 'Details')">
					<div class="detail-grid">
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Title') }}</span>
							<span class="detail-value">{{ entity?.title }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Lifecycle') }}</span>
							<span class="detail-value">
								<CnStatusBadge :label="entity?.lifecycle || ''" />
							</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Version') }}</span>
							<span class="detail-value">{{ entity?.version }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Approved at') }}</span>
							<span class="detail-value">{{ formatDate(entity?.approvedAt) }}</span>
						</div>
						<div class="detail-row">
							<span class="detail-label">{{ t('decidesk', 'Signed by') }}</span>
							<span class="detail-value">{{ (entity?.signedBy || []).join(', ') }}</span>
						</div>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Lifecycle')">
					<CnTimelineStages
						:stages="lifecycleStages"
						:current-stage="entity?.lifecycle || 'draft'" />
					<div class="lifecycle-actions">
						<NcButton
							v-if="entity?.lifecycle === 'draft'"
							type="primary"
							@click="transitionLifecycle('review')">
							{{ t('decidesk', 'Ter goedkeuring indienen') }}
						</NcButton>
						<NcButton
							v-if="entity?.lifecycle === 'review'"
							type="primary"
							@click="transitionLifecycle('approved')">
							{{ t('decidesk', 'Goedkeuren') }}
						</NcButton>
						<NcButton
							v-if="entity?.lifecycle === 'approved'"
							type="primary"
							@click="transitionLifecycle('signed')">
							{{ t('decidesk', 'Ondertekenen') }}
						</NcButton>
						<NcButton
							v-if="entity?.lifecycle === 'signed'"
							type="primary"
							@click="transitionLifecycle('published')">
							{{ t('decidesk', 'Publiceren') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Content')">
					<NcButton
						v-if="entity?.lifecycle === 'draft'"
						type="secondary"
						:disabled="generating"
						@click="generateDraft">
						{{ t('decidesk', 'Concept genereren') }}
					</NcButton>
					<div v-if="entity?.content" class="minutes-content">
						{{ entity.content }}
					</div>
					<p v-else class="empty-content">
						{{ t('decidesk', 'No content yet. Use "Concept genereren" to generate a draft from the linked meeting.') }}
					</p>
				</CnDetailCard>
			</div>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="entity?.id"
				:object-id="entity.id"
				:object-type="'minutes'" />
		</template>
	</CnDetailPage>

	<!-- Preview dialog for generated draft -->
	<NcDialog
		v-if="showPreview"
		:name="t('decidesk', 'Draft preview')"
		@close="showPreview = false">
		<div class="preview-content">
			{{ previewContent }}
		</div>
		<template #actions>
			<NcButton type="secondary" @click="showPreview = false">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" @click="applyDraft">
				{{ t('decidesk', 'Apply') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { CnDetailCard, CnDetailPage, CnObjectSidebar, CnStatusBadge, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'MinutesDetail',
	components: {
		CnDetailCard,
		CnDetailPage,
		CnObjectSidebar,
		CnStatusBadge,
		CnTimelineStages,
		NcButton,
		NcDialog,
	},
	props: {
		entityId: {
			type: String,
			required: true,
		},
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('minutes', {
			objectStore,
		})
		return { detailView }
	},
	data() {
		return {
			editMode: false,
			generating: false,
			showPreview: false,
			previewContent: '',
			lifecycleStages: [
				{ id: 'draft', label: this.t('decidesk', 'Draft') },
				{ id: 'review', label: this.t('decidesk', 'Review') },
				{ id: 'approved', label: this.t('decidesk', 'Approved') },
				{ id: 'signed', label: this.t('decidesk', 'Signed') },
				{ id: 'published', label: this.t('decidesk', 'Published') },
			],
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
		async generateDraft() {
			this.generating = true
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.entityId}/generate-draft`)
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.previewContent = data.preview
					this.showPreview = true
				}
			} finally {
				this.generating = false
			}
		},
		async applyDraft() {
			const objectStore = useObjectStore()
			const updated = { ...this.entity, content: this.previewContent }
			await objectStore.saveObject?.('minutes', updated)
			this.showPreview = false
			this.detailView?.refresh?.()
		},
		async transitionLifecycle(newState) {
			const objectStore = useObjectStore()
			const updated = { ...this.entity, lifecycle: newState }

			if (newState === 'approved') {
				updated.approvedAt = new Date().toISOString()
				const userName = OC.getCurrentUser()?.displayName || OC.getCurrentUser()?.uid || ''
				updated.signedBy = [...(updated.signedBy || []), userName]
				updated.version = (updated.version || 1) + 1
			}

			if (newState === 'signed') {
				const userName = OC.getCurrentUser()?.displayName || OC.getCurrentUser()?.uid || ''
				if (!(updated.signedBy || []).includes(userName)) {
					updated.signedBy = [...(updated.signedBy || []), userName]
				}
			}

			await objectStore.saveObject?.('minutes', updated)
			this.detailView?.refresh?.()
		},
		async deleteEntity() {
			const objectStore = useObjectStore()
			await objectStore.deleteObject?.('minutes', this.entityId)
			this.$router.push({ name: 'Minutes' })
		},
	},
}
</script>

<style scoped>
.minutes-detail {
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

.lifecycle-actions {
	margin-top: 12px;
	display: flex;
	gap: 8px;
}

.minutes-content {
	white-space: pre-wrap;
	line-height: 1.6;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.empty-content {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.preview-content {
	white-space: pre-wrap;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
	padding: 12px;
}
</style>
