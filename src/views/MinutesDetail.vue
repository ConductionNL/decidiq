<!-- TODO(decidesk-manifest-v1): obsolete after @conduction/nextcloud-vue release ships manifest-page-type-extensions + manifest-abstract-sidebar; delete in cleanup commit -->
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
				<CnDetailCard :title="t('decidesk', 'Lifecycle')">
					<CnTimelineStages
						:stages="lifecycleStages"
						:current-stage="object.lifecycle || 'draft'" />
					<div class="decidesk-transitions">
						<NcButton
							v-for="action in availableTransitions"
							:key="action.to"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle(action.to)">
							{{ action.label }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'draft'"
							:disabled="generating"
							@click="generateDraft">
							{{ t('decidesk', 'Concept genereren') }}
						</NcButton>
						<p v-if="transitionError" class="decidesk-error">
							{{ transitionError }}
						</p>
						<p v-if="generateError" class="decidesk-error">
							{{ generateError }}
						</p>
					</div>
				</CnDetailCard>
				<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
					<CnDetailGrid :items="propertyItems" />
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

		<NcDialog
			v-if="showDraftModal"
			:name="t('decidesk', 'Concept gegenereerd')"
			:open="showDraftModal"
			@update:open="showDraftModal = false">
			<template #default>
				<p>{{ t('decidesk', 'Bekijk het gegenereerde concept. Klik op "Toepassen" om de inhoud te overschrijven.') }}</p>
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
			generating: false,
			transitionError: null,
			generateError: null,
			showDraftModal: false,
			draftPreview: '',
			lifecycleStages: [
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
		/**
		 * Returns the single available next transition based on the current lifecycle stage.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		availableTransitions() {
			const map = {
				draft: { to: 'review', label: this.t('decidesk', 'Ter beoordeling indienen') },
				review: { to: 'approved', label: this.t('decidesk', 'Goedkeuren') },
				approved: { to: 'signed', label: this.t('decidesk', 'Ondertekenen') },
				signed: { to: 'published', label: this.t('decidesk', 'Publiceren') },
			}
			const current = this.object?.lifecycle || 'draft'
			return map[current] ? [map[current]] : []
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Status'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Versie'), value: this.object.version },
				{ label: this.t('decidesk', 'Goedgekeurd op'), value: this.formatDate(this.object.approvedAt) },
				{ label: this.t('decidesk', 'Ondertekend door'), value: (this.object.signedBy || []).join(', ') },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('minutes', this.id)
		},
		/**
		 * Calls the server-side lifecycle transition endpoint.
		 * signedBy is populated from the authenticated session server-side.
		 *
		 * @param {string} newLifecycle - The target lifecycle stage
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		async transitionLifecycle(newLifecycle) {
			this.transitioning = true
			this.transitionError = null
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
				} else {
					const err = await response.json().catch(() => ({}))
					this.transitionError = err.message || this.t('decidesk', 'Verzoek mislukt.')
				}
			} finally {
				this.transitioning = false
			}
		},
		async generateDraft() {
			this.generating = true
			this.generateError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/generate-draft`)
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.draftPreview = data.preview
					this.showDraftModal = true
				} else {
					const err = await response.json().catch(() => ({}))
					this.generateError = err.message || this.t('decidesk', 'Genereren mislukt.')
				}
			} finally {
				this.generating = false
			}
		},
		async applyDraft() {
			await this.objectStore.saveObject('minutes', {
				...this.object,
				content: this.draftPreview,
			})
			this.showDraftModal = false
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
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

.decidesk-draft-preview {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-face);
	font-size: 14px;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
}

.decidesk-error {
	color: var(--color-error);
	margin: 4px 0 0;
	font-size: 0.875em;
	width: 100%;
}
</style>
