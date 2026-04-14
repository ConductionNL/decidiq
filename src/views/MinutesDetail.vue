<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.3
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Notulen')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Lifecycle timeline -->
			<CnDetailCard :title="t('decidesk', 'Levenscyclus')">
				<CnTimelineStages
					:stages="lifecycleStages"
					:current-stage="object.lifecycle" />
				<!-- Lifecycle transition buttons -->
				<div class="decidesk-lifecycle-actions">
					<NcButton
						v-if="object.lifecycle === 'draft'"
						type="primary"
						@click="transitionLifecycle('review')">
						{{ t('decidesk', 'Ter goedkeuring indienen') }}
					</NcButton>
					<NcButton
						v-if="object.lifecycle === 'review'"
						type="primary"
						@click="transitionToApproved">
						{{ t('decidesk', 'Goedkeuren') }}
					</NcButton>
					<NcButton
						v-if="object.lifecycle === 'approved'"
						type="primary"
						@click="transitionLifecycle('signed')">
						{{ t('decidesk', 'Ondertekenen') }}
					</NcButton>
					<NcButton
						v-if="object.lifecycle === 'signed'"
						type="primary"
						@click="transitionLifecycle('published')">
						{{ t('decidesk', 'Publiceren') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Generate draft button (only for draft lifecycle) -->
			<CnDetailCard
				v-if="object.lifecycle === 'draft'"
				:title="t('decidesk', 'Concept genereren')">
				<p class="decidesk-generate-hint">
					{{ t('decidesk', 'Genereer een concept op basis van de gekoppelde vergadering en agendapunten.') }}
				</p>
				<NcButton
					:disabled="generatingDraft"
					type="secondary"
					@click="generateDraft">
					{{ generatingDraft ? t('decidesk', 'Bezig met genereren...') : t('decidesk', 'Concept genereren') }}
				</NcButton>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar :object="object" :loading="loading" />
		</template>

		<template #edit-dialog>
			<CnSchemaFormDialog
				v-if="editing"
				:schema="schema"
				:object="object"
				:title="t('decidesk', 'Notulen bewerken')"
				:object-store="objectStore"
				object-type="minutes"
				@close="editing = false"
				@saved="onEditSaved" />
		</template>

		<template #delete-dialog>
			<CnDeleteDialog
				v-if="showDeleteDialog"
				:object-name="object.title || ''"
				@confirm="confirmDelete"
				@close="showDeleteDialog = false" />
		</template>
	</CnDetailPage>

	<!-- Preview modal for generated draft -->
	<NcDialog
		v-if="showPreviewModal"
		:name="t('decidesk', 'Gegenereerd concept')"
		:can-close="true"
		@close="showPreviewModal = false">
		<p class="decidesk-preview-warning">
			{{ t('decidesk', 'Controleer het gegenereerde concept vóór u het opslaat. Dit overschrijft de huidige inhoud.') }}
		</p>
		<div class="decidesk-preview-content">
			<pre>{{ previewText }}</pre>
		</div>
		<template #actions>
			<NcButton type="tertiary" @click="showPreviewModal = false">
				{{ t('decidesk', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" @click="applyDraft">
				{{ t('decidesk', 'Concept toepassen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Minutes detail view with lifecycle management and draft generation.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.3
 */
export default {
	name: 'MinutesDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
		NcButton,
		NcDialog,
	},
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('minutes', props.id, {
			objectStore,
			listRouteName: 'Minutes',
			detailRouteName: 'MinutesDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			generatingDraft: false,
			showPreviewModal: false,
			previewText: '',
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('minutes')
		},
		/**
		 * Lifecycle stages for the CnTimelineStages component.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		lifecycleStages() {
			return [
				{ id: 'draft', label: this.t('decidesk', 'Concept') },
				{ id: 'review', label: this.t('decidesk', 'Ter goedkeuring') },
				{ id: 'approved', label: this.t('decidesk', 'Goedgekeurd') },
				{ id: 'signed', label: this.t('decidesk', 'Ondertekend') },
				{ id: 'published', label: this.t('decidesk', 'Gepubliceerd') },
			]
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Titel'), value: this.object.title },
				{ label: this.t('decidesk', 'Levenscyclus'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Versie'), value: this.object.version },
				{ label: this.t('decidesk', 'Goedgekeurd op'), value: this.object.approvedAt },
				{ label: this.t('decidesk', 'Ondertekend door'), value: Array.isArray(this.object.signedBy) ? this.object.signedBy.join(', ') : this.object.signedBy },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('minutes', this.id)
		},
		/**
		 * Transition the Minutes lifecycle to the given state.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.3
		 */
		async transitionLifecycle(newLifecycle) {
			try {
				await this.objectStore.saveObject('minutes', this.id, {
					...this.object,
					lifecycle: newLifecycle,
				})
				await this.objectStore.fetchObject('minutes', this.id)
			} catch (error) {
				console.error('Failed to transition lifecycle:', error)
			}
		},
		/**
		 * Transition to "approved" — sets approvedAt and appends current user to signedBy.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.3
		 */
		async transitionToApproved() {
			const now = new Date().toISOString()
			const signedBy = Array.isArray(this.object.signedBy) ? [...this.object.signedBy] : []
			// Append current user display name if available.
			if (window.OC?.currentUser) {
				signedBy.push(window.OC.currentUser)
			}

			try {
				await this.objectStore.saveObject('minutes', this.id, {
					...this.object,
					lifecycle: 'approved',
					approvedAt: now,
					signedBy,
				})
				await this.objectStore.fetchObject('minutes', this.id)
			} catch (error) {
				console.error('Failed to approve minutes:', error)
			}
		},
		/**
		 * Call the generate-draft endpoint and show the preview modal.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		async generateDraft() {
			this.generatingDraft = true
			try {
				const url = generateUrl('/apps/decidesk/api/minutes/{id}/generate-draft', { id: this.id })
				const response = await axios.post(url)
				this.previewText = response.data.preview || ''
				this.showPreviewModal = true
			} catch (error) {
				console.error('Failed to generate draft:', error)
			} finally {
				this.generatingDraft = false
			}
		},
		/**
		 * Apply the generated draft by saving the content field and closing the modal.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		async applyDraft() {
			try {
				await this.objectStore.saveObject('minutes', this.id, {
					...this.object,
					content: this.previewText,
				})
				await this.objectStore.fetchObject('minutes', this.id)
			} catch (error) {
				console.error('Failed to apply draft:', error)
			} finally {
				this.showPreviewModal = false
				this.previewText = ''
			}
		},
	},
}
</script>

<style scoped>
.decidesk-lifecycle-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin-top: var(--default-grid-baseline);
}

.decidesk-generate-hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 var(--default-grid-baseline);
}

.decidesk-preview-warning {
	color: var(--color-warning);
	margin: 0 0 var(--default-grid-baseline);
}

.decidesk-preview-content {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline);
	background: var(--color-background-dark);
}

.decidesk-preview-content pre {
	white-space: pre-wrap;
	word-wrap: break-word;
	margin: 0;
	font-size: 0.875rem;
	color: var(--color-main-text);
}
</style>
