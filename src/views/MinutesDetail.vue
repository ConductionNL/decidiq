<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5
-->
<template>
	<div>
		<CnDetailPage
			:object="object"
			:loading="loading"
			:title="object.title || t('decidesk', 'Notulen')"
			:show-sidebar="true"
			@edit="editing = true"
			@delete="showDeleteDialog = true">
			<template #properties>
				<CnDetailCard :title="t('decidesk', 'Status')">
					<CnTimelineStages
						:stages="stages"
						:current-stage="object.lifecycle" />
					<div class="decidesk-lifecycle-actions">
						<NcButton
							v-if="object.lifecycle === 'draft'"
							type="secondary"
							@click="generateDraft">
							{{ t('decidesk', 'Concept genereren') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'draft'"
							type="primary"
							:disabled="transitioning"
							@click="transition('review')">
							{{ t('decidesk', 'Ter beoordeling indienen') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'review'"
							type="primary"
							:disabled="transitioning"
							@click="transition('approved')">
							{{ t('decidesk', 'Goedkeuren') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'approved'"
							type="primary"
							:disabled="transitioning"
							@click="transition('signed')">
							{{ t('decidesk', 'Ondertekenen') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'signed'"
							type="primary"
							:disabled="transitioning"
							@click="transition('published')">
							{{ t('decidesk', 'Publiceren') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
					<CnDetailGrid :items="propertyItems" />
				</CnDetailCard>

				<CnDetailCard v-if="object.content" :title="t('decidesk', 'Inhoud')">
					<pre class="decidesk-minutes-content">{{ object.content }}</pre>
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

		<!-- Generate draft preview dialog -->
		<NcDialog
			v-if="showDraftModal"
			:name="t('decidesk', 'Concept gegenereerd')"
			:open="showDraftModal"
			@update:open="showDraftModal = false">
			<template #default>
				<p>{{ t('decidesk', 'Bekijk het gegenereerde concept hieronder. Klik op "Toepassen" om de inhoud te overschrijven.') }}</p>
				<pre class="decidesk-draft-preview">{{ draftPreview }}</pre>
			</template>
			<template #actions>
				<NcButton type="primary" @click="applyDraft">{{ t('decidesk', 'Toepassen') }}</NcButton>
				<NcButton @click="showDraftModal = false">{{ t('decidesk', 'Annuleren') }}</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'MinutesDetail',
	components: { NcButton, NcDialog, CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages },
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
			transitioning: false,
			showDraftModal: false,
			draftPreview: '',
			stages: [
				{ key: 'draft', label: t('decidesk', 'Concept') },
				{ key: 'review', label: t('decidesk', 'Ter beoordeling') },
				{ key: 'approved', label: t('decidesk', 'Goedgekeurd') },
				{ key: 'signed', label: t('decidesk', 'Ondertekend') },
				{ key: 'published', label: t('decidesk', 'Gepubliceerd') },
			],
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('minutes')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Versie'), value: this.object.version ?? 1 },
				{ label: this.t('decidesk', 'Goedgekeurd op'), value: this.formatDate(this.object.approvedAt) },
				{ label: this.t('decidesk', 'Ondertekend door'), value: (this.object.signedBy ?? []).join(', ') },
			].filter((item) => item.value || item.value === 0)
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('minutes', this.id)
		},
		/**
		 * Call server-side lifecycle transition endpoint.
		 * Server validates the transition and populates signedBy from session user display name.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5
		 */
		async transition(to) {
			this.transitioning = true
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/transition`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ to }),
				})
				if (response.ok) {
					this.objectStore.fetchObject('minutes', this.id)
				}
			} finally {
				this.transitioning = false
			}
		},
		async generateDraft() {
			const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/generate-draft`)
			const response = await fetch(url, {
				method: 'POST',
				headers: { requesttoken: OC.requestToken },
			})
			if (response.ok) {
				const data = await response.json()
				this.draftPreview = data.preview
				this.showDraftModal = true
			}
		},
		async applyDraft() {
			await this.objectStore.saveObject('minutes', {
				...this.object,
				content: this.draftPreview,
			})
			this.showDraftModal = false
			this.objectStore.fetchObject('minutes', this.id)
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-lifecycle-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 12px;
}

.decidesk-minutes-content {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-face);
	font-size: 14px;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
}

.decidesk-draft-preview {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-face);
	font-size: 14px;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
}
</style>
