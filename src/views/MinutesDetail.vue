<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
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
				<!-- Lifecycle timeline -->
				<CnDetailCard :title="t('decidesk', 'Status')">
					<CnTimelineStages
						:stages="stages"
						:current-stage="object.lifecycle || 'draft'" />

					<!-- Lifecycle transition buttons -->
					<div v-if="object.lifecycle !== 'published'" class="decidesk-transitions">
						<NcButton
							v-if="object.lifecycle === 'draft'"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle('review')">
							{{ t('decidesk', 'Ter beoordeling indienen') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'review'"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle('approved')">
							{{ t('decidesk', 'Goedkeuren') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'approved'"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle('signed')">
							{{ t('decidesk', 'Ondertekenen') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'signed'"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle('published')">
							{{ t('decidesk', 'Publiceren') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'draft'"
							:disabled="transitioning"
							@click="generateDraft">
							{{ t('decidesk', 'Concept genereren') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<!-- Properties -->
				<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
					<CnDetailGrid :items="propertyItems" />
				</CnDetailCard>
			</template>

			<template #relations>
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

		<!-- Generate draft preview modal -->
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
				<NcButton type="primary" @click="applyDraft">
					{{ t('decidesk', 'Toepassen') }}
				</NcButton>
				<NcButton @click="showDraftModal = false">
					{{ t('decidesk', 'Annuleren') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'MinutesDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, NcButton, NcDialog },
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
				{ key: 'draft', label: this.t('decidesk', 'Concept') },
				{ key: 'review', label: this.t('decidesk', 'Ter beoordeling') },
				{ key: 'approved', label: this.t('decidesk', 'Goedgekeurd') },
				{ key: 'signed', label: this.t('decidesk', 'Ondertekend') },
				{ key: 'published', label: this.t('decidesk', 'Gepubliceerd') },
			],
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('minutes')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Status'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Versie'), value: this.object.version },
				{ label: this.t('decidesk', 'Goedgekeurd op'), value: this.formatDate(this.object.approvedAt) },
				{ label: this.t('decidesk', 'Ondertekend door'), value: (this.object.signedBy || []).join(', ') },
			].filter((item) => item.value)
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('minutes', this.id)
		},
		/**
		 * Transition the Minutes lifecycle via the server-side endpoint.
		 * Server enforces valid sequence and populates signedBy from the session.
		 *
		 * @param {string} newLifecycle The target lifecycle state
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		async transitionLifecycle(newLifecycle) {
			this.transitioning = true
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/transition`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ lifecycle: newLifecycle }),
				})
				if (response.ok) {
					await this.objectStore.fetchObject('minutes', this.id)
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
			await this.objectStore.saveObject('minutes', { ...this.object, content: this.draftPreview })
			this.showDraftModal = false
			await this.objectStore.fetchObject('minutes', this.id)
		},
		formatDate(dateStr) {
			if (!dateStr) return null
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-transitions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
	flex-wrap: wrap;
}

.decidesk-minutes-content,
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
