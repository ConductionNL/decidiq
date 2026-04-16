<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-9.2
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.7
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Agenda Item')"
		:sidebar="true"
		:object-type="'agenda-item'"
		:object-id="id"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Linked Meeting')">
				<p v-if="!object.relations?.meeting?.length" class="decidesk-empty">
					{{ t('decidesk', 'No linked meeting.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="meeting in object.relations.meeting" :key="meeting.id || meeting">
						<router-link :to="{ name: 'MeetingDetail', params: { id: meeting.id || meeting } }">
							{{ meeting.title || meeting.name || meeting.id || meeting }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>

			<!-- Motion linking — only for 'decision'-type agenda items -->
			<CnDetailCard v-if="object.itemType === 'decision'" :title="t('decidesk', 'Linked Motion')">
				<p v-if="!linkedMotions.length" class="decidesk-empty">
					{{ t('decidesk', 'No motion linked to this decision item.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="motion in linkedMotions" :key="motion.id || motion">
						<router-link :to="{ name: 'MotionDetail', params: { id: motion.id || motion } }">
							{{ motion.title || motion.id || motion }}
						</router-link>
					</li>
				</ul>

				<button class="decidesk-link-btn" @click="showLinkMotion = true">
					{{ t('decidesk', 'Link motion') }}
				</button>

				<!-- Inline motion search dialog -->
				<div v-if="showLinkMotion" class="decidesk-link-dialog">
					<h3>{{ t('decidesk', 'Link motion') }}</h3>
					<input
						v-model="motionSearch"
						type="text"
						:placeholder="t('decidesk', 'Search by motion title…')"
						class="decidesk-search-input"
						@input="fetchMotions">
					<ul v-if="motionResults.length" class="decidesk-motion-list">
						<li
							v-for="m in motionResults"
							:key="m.id || m.uuid"
							:class="{ selected: selectedMotionId === (m.id || m.uuid) }"
							@click="selectedMotionId = m.id || m.uuid">
							{{ m.title || m.id }}
						</li>
					</ul>
					<p v-else-if="motionSearch.length > 1" class="decidesk-empty">
						{{ t('decidesk', 'No motions found.') }}
					</p>
					<div class="decidesk-dialog-actions">
						<button :disabled="!selectedMotionId" @click="linkMotion">
							{{ t('decidesk', 'Link') }}
						</button>
						<button @click="closeLinkDialog">
							{{ t('decidesk', 'Cancel') }}
						</button>
					</div>
				</div>
			</CnDetailCard>
		</template>

		<template #edit-dialog>
			<CnSchemaFormDialog
				v-if="editing"
				:schema="schema"
				:object="object"
				:title="t('decidesk', 'Edit Agenda Item')"
				:object-store="objectStore"
				object-type="agenda-item"
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
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'AgendaItemDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnSchemaFormDialog, CnDeleteDialog },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('agenda-item', props.id, {
			objectStore,
			listRouteName: 'AgendaItems',
			detailRouteName: 'AgendaItemDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			showLinkMotion: false,
			motionSearch: '',
			motionResults: [],
			selectedMotionId: null,
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('agenda-item')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Type'), value: this.object.itemType },
				{ label: this.t('decidesk', 'Order'), value: this.object.orderNumber },
				{ label: this.t('decidesk', 'Estimated Duration'), value: this.object.estimatedDuration ? `${this.object.estimatedDuration} min` : '' },
				{ label: this.t('decidesk', 'Actual Duration'), value: this.object.actualDuration ? `${this.object.actualDuration} min` : '' },
				{ label: this.t('decidesk', 'Description'), value: this.object.description },
				{ label: this.t('decidesk', 'Recurring'), value: this.object.isRecurring ? this.t('decidesk', 'Yes') : this.t('decidesk', 'No') },
			]
		},
		linkedMotions() {
			return this.object.relations?.motion || []
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('agenda-item', this.id)
		},
		async fetchMotions() {
			if (this.motionSearch.length < 2) {
				this.motionResults = []
				return
			}
			try {
				const resp = await fetch(
					OC.generateUrl(`/apps/decidesk/api/objects/motion?search=${encodeURIComponent(this.motionSearch)}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (resp.ok) {
					const data = await resp.json()
					this.motionResults = data.results || data || []
				}
			} catch (e) {
				this.motionResults = []
			}
		},
		async linkMotion() {
			if (!this.selectedMotionId) return
			const updated = { ...this.object }
			if (!updated.relations) updated.relations = {}
			if (!updated.relations.motion) updated.relations.motion = []
			const alreadyLinked = updated.relations.motion.some(
				(m) => (m.id || m) === this.selectedMotionId,
			)
			if (!alreadyLinked) {
				updated.relations.motion.push({ id: this.selectedMotionId })
			}
			try {
				await this.objectStore.saveObject('agenda-item', updated)
				await this.objectStore.fetchObject('agenda-item', this.id)
			} catch (e) {
				// ignore
			}
			this.closeLinkDialog()
		},
		closeLinkDialog() {
			this.showLinkMotion = false
			this.motionSearch = ''
			this.motionResults = []
			this.selectedMotionId = null
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-relations {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidesk-relations li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-relations li:last-child {
	border-bottom: none;
}

.decidesk-link-btn {
	margin-top: var(--default-grid-baseline);
}

.decidesk-link-dialog {
	margin-top: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline);
}

.decidesk-link-dialog h3 {
	font-size: var(--default-font-size);
	font-weight: bold;
	margin: 0 0 var(--default-grid-baseline);
}

.decidesk-search-input {
	width: 100%;
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-motion-list {
	list-style: none;
	margin: 0 0 var(--default-grid-baseline);
	padding: 0;
	max-height: 200px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.decidesk-motion-list li {
	padding: var(--default-grid-baseline);
	cursor: pointer;
}

.decidesk-motion-list li:hover,
.decidesk-motion-list li.selected {
	background-color: var(--color-background-hover);
}

.decidesk-dialog-actions {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
