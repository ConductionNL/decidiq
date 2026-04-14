<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-7.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-3.1
 @spec openspec/changes/p2-agenda-management/tasks.md#task-3.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-3.3
 @spec openspec/changes/p2-agenda-management/tasks.md#task-4.5
 @spec openspec/changes/p2-agenda-management/tasks.md#task-5.3
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Meeting')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<!-- Agenda section with builder and publication -->
			<CnDetailCard :title="t('decidesk', 'Agenda')">
				<!-- Publication actions -->
				<div class="meeting-detail__agenda-actions">
					<NcButton
						v-if="isChairOrSecretary && !isPublished"
						type="primary"
						:loading="publishing"
						:aria-label="t('decidesk', 'Publish agenda')"
						@click="publishAgenda">
						{{ t('decidesk', 'Publish agenda') }}
					</NcButton>
					<NcButton
						v-if="isChairOrSecretary && isPublished"
						:aria-label="t('decidesk', 'Revise agenda')"
						@click="reviseAgenda">
						{{ t('decidesk', 'Revise agenda') }}
					</NcButton>

					<!-- Export button -->
					<CnMassExportDialog
						v-if="agendaItemsSorted.length > 0"
						:objects="agendaItemsSorted"
						:columns="exportColumns"
						:title="t('decidesk', 'Export agenda')"
						:filename="t('decidesk', 'agenda')">
						<template #trigger="{ open }">
							<NcButton :aria-label="t('decidesk', 'Export agenda')" @click="open">
								{{ t('decidesk', 'Export') }}
							</NcButton>
						</template>
					</CnMassExportDialog>

					<!-- Live meeting link (only when opened) -->
					<NcButton
						v-if="object.lifecycle === 'opened'"
						:aria-label="t('decidesk', 'Open live meeting view')"
						@click="$router.push({ name: 'LiveMeeting', params: { id: id } })">
						{{ t('decidesk', 'Live meeting') }}
					</NcButton>
				</div>

				<!-- Publish validation error -->
				<p v-if="publishError" class="meeting-detail__error" role="alert">
					{{ publishError }}
				</p>

				<!-- Agenda builder component -->
				<AgendaBuilder
					:meeting-id="id"
					:is-chair="isChairOrSecretary"
					:lifecycle="object.lifecycle || 'scheduled'"
					:items="agendaItemsSorted"
					:participants="meetingParticipants"
					@reordered="onReordered"
					@item-updated="refreshAgendaItems" />
			</CnDetailCard>

			<!-- COI declarations (chair/secretary only) -->
			<CnDetailCard
				v-if="isChairOrSecretary && coiItems.length > 0"
				:title="t('decidesk', 'Conflict of interest declarations')">
				<ul class="meeting-detail__coi-list" role="list">
					<li v-for="item in coiItems"
						:key="item.id"
						class="meeting-detail__coi-item"
						role="listitem">
						<strong>{{ item.title }}</strong>
						<ul class="meeting-detail__coi-declarations" role="list">
							<li v-for="note in coiNotes(item)" :key="note.id" role="listitem">
								{{ note.title.replace('COI: ', '') }}:
								{{ note.content || note.body || '' }}
							</li>
						</ul>
					</li>
				</ul>
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
				:title="t('decidesk', 'Edit Meeting')"
				:object-store="objectStore"
				object-type="meeting"
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
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnMassExportDialog, useDetailView } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { useObjectStore } from '../store/store.js'
import AgendaBuilder from '../components/AgendaBuilder.vue'

export default {
	name: 'MeetingDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnMassExportDialog, NcButton, AgendaBuilder },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('meeting', props.id, {
			objectStore,
			listRouteName: 'Meetings',
			detailRouteName: 'MeetingDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			publishing: false,
			publishError: null,
			meetingParticipants: [],
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('meeting')
		},
		agendaItemsSorted() {
			return (this.object.relations?.['agenda-item'] ?? [])
				.slice()
				.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
		},
		isPublished() {
			return this.object.lifecycle === 'opened'
		},
		isChairOrSecretary() {
			const currentUser = getCurrentUser()
			if (!currentUser) return false
			return this.meetingParticipants.some(
				p => p.owner === currentUser.uid && ['chair', 'secretary'].includes(p.role),
			)
		},
		coiItems() {
			return this.agendaItemsSorted.filter(item => this.coiNotes(item).length > 0)
		},
		exportColumns() {
			return [
				{ key: 'orderNumber', label: this.t('decidesk', 'Number') },
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'itemType', label: this.t('decidesk', 'Type') },
				{ key: 'estimatedDuration', label: this.t('decidesk', 'Duration (min)') },
				{ key: 'spokesperson', label: this.t('decidesk', 'Spokesperson') },
			]
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Type'), value: this.object.meetingType },
				{ label: this.t('decidesk', 'Scheduled Date'), value: this.object.scheduledDate },
				{ label: this.t('decidesk', 'End Date'), value: this.object.endDate },
				{ label: this.t('decidesk', 'Location'), value: this.object.location },
				{ label: this.t('decidesk', 'Mode'), value: this.object.meetingMode },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Quorum Required'), value: this.object.quorumRequired },
				{ label: this.t('decidesk', 'Series'), value: this.object.series },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('meeting', this.id)
		},
		async publishAgenda() {
			if (this.agendaItemsSorted.length === 0) {
				this.publishError = this.t('decidesk', 'Cannot publish: no agenda items.')
				return
			}
			this.publishing = true
			this.publishError = null
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/decidesk/api/agendas/${this.id}/publish`),
					{
						method: 'POST',
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					this.publishError = data.message || this.t('decidesk', 'Failed to publish agenda.')
					return
				}
				await this.objectStore.fetchObject('meeting', this.id)
			} catch (e) {
				this.publishError = this.t('decidesk', 'Failed to publish agenda.')
				console.error(e)
			} finally {
				this.publishing = false
			}
		},
		async reviseAgenda() {
			try {
				await this.objectStore.saveObject('meeting', { ...this.object, lifecycle: 'scheduled' })
				await this.objectStore.fetchObject('meeting', this.id)
			} catch (e) {
				console.error('Failed to revise agenda:', e)
			}
		},
		onReordered() {
			this.objectStore.fetchObject('meeting', this.id)
		},
		refreshAgendaItems() {
			this.objectStore.fetchObject('meeting', this.id)
		},
		coiNotes(item) {
			return (item?.notes ?? []).filter(n => (n.title ?? '').startsWith('COI:'))
		},
	},

	async created() {
		try {
			const parts = await this.objectStore.fetchObjects('participant', {
				'@self.relations.meeting': this.id,
			})
			this.meetingParticipants = parts ?? []
		} catch (e) {
			console.error('Failed to fetch meeting participants:', e)
		}
	},
}
</script>

<style scoped>
.meeting-detail__agenda-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin-bottom: var(--default-grid-baseline);
}

.meeting-detail__error {
	color: var(--color-error);
	margin: var(--default-grid-baseline) 0;
}

.meeting-detail__coi-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.meeting-detail__coi-item {
	margin-bottom: var(--default-grid-baseline);
}

.meeting-detail__coi-declarations {
	list-style: disc;
	padding-left: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}
</style>
