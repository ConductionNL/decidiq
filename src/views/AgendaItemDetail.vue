<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-9.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-5.1
 @spec openspec/changes/p2-agenda-management/tasks.md#task-6.1
 @spec openspec/changes/p2-agenda-management/tasks.md#task-6.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-7.1
 @spec openspec/changes/p2-agenda-management/tasks.md#task-7.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-7.3
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Agenda Item')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<!-- Item type badge -->
				<CnStatusBadge
					v-if="object.itemType"
					:status="object.itemType"
					class="agenda-item-detail__type-badge"
					:aria-label="t('decidesk', 'Type: {type}', { type: itemTypeLabel })" />
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- BOB phase timeline (discussion/decision only) -->
			<CnDetailCard
				v-if="hasBobPhase"
				:title="t('decidesk', 'BOB phase')">
				<CnTimelineStages
					:stages="bobStages"
					:current="currentBobStageIndex"
					:aria-label="t('decidesk', 'BOB phase progression')" />
				<p v-if="coiCount > 0" class="agenda-item-detail__coi-count" aria-live="polite">
					{{ t('decidesk', 'COI ({n})', { n: coiCount }) }}
				</p>
			</CnDetailCard>
		</template>

		<template #relations>
			<!-- Linked meeting -->
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

			<!-- Spokesperson -->
			<CnDetailCard :title="t('decidesk', 'Spokesperson')">
				<p v-if="!spokespersonName" class="decidesk-empty">
					{{ t('decidesk', 'No spokesperson assigned.') }}
				</p>
				<p v-else>{{ spokespersonName }}</p>
			</CnDetailCard>

			<!-- Linked Motions (decision type only) -->
			<CnDetailCard
				v-if="object.itemType === 'decision'"
				:title="t('decidesk', 'Linked motions')">
				<p v-if="!linkedMotions.length" class="decidesk-empty">
					{{ t('decidesk', 'No linked motions.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="motion in linkedMotions" :key="motion.id || motion">
						<router-link :to="{ name: 'MotionDetail', params: { id: motion.id || motion } }">
							{{ motion.title || motion.name || motion.id || motion }}
						</router-link>
					</li>
				</ul>

				<NcButton
					:aria-label="t('decidesk', 'Link a motion to this agenda item')"
					@click="showMotionLinkDialog = true">
					{{ t('decidesk', 'Link motion') }}
				</NcButton>

				<NcDialog
					v-if="showMotionLinkDialog"
					:name="t('decidesk', 'Link motion')"
					@closing="showMotionLinkDialog = false">
					<template #default>
						<p>{{ t('decidesk', 'Select a motion from the same meeting to link.') }}</p>
						<ul v-if="availableMotions.length > 0" class="decidesk-relations" role="list">
							<li v-for="m in availableMotions" :key="m.id" role="listitem">
								<NcButton @click="linkMotion(m)">
									{{ m.title }}
								</NcButton>
							</li>
						</ul>
						<p v-else class="decidesk-empty">
							{{ t('decidesk', 'No motions found for this meeting.') }}
						</p>
					</template>
				</NcDialog>
			</CnDetailCard>

			<!-- COI declaration button (all participants) -->
			<CnDetailCard :title="t('decidesk', 'Conflict of interest')">
				<NcButton
					:aria-label="t('decidesk', 'Declare conflict of interest for this agenda item')"
					@click="showCoiDialog = true">
					{{ t('decidesk', 'Declare conflict of interest') }}
				</NcButton>

				<NcDialog
					v-if="showCoiDialog"
					:name="t('decidesk', 'Declare conflict of interest')"
					@closing="showCoiDialog = false">
					<template #default>
						<NcTextArea
							v-model="coiReason"
							:label="t('decidesk', 'Reason for recusal')"
							:placeholder="t('decidesk', 'Describe your reason for declaring a conflict of interest')"
							required />
					</template>
					<template #actions>
						<NcButton
							:disabled="!coiReason"
							type="primary"
							@click="submitCoi">
							{{ t('decidesk', 'Submit declaration') }}
						</NcButton>
					</template>
				</NcDialog>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<!-- CnObjectSidebar provides Files, Notes (COI declarations visible), and Audit Trail tabs -->
			<CnObjectSidebar :object="object" :loading="loading" />
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
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnStatusBadge, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

const BOB_STAGES = [
	{ id: 'beeldvorming', label: 'Beeldvorming' },
	{ id: 'oordeelsvorming', label: 'Oordeelsvorming' },
	{ id: 'besluitvorming', label: 'Besluitvorming' },
]

export default {
	name: 'AgendaItemDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnStatusBadge,
		CnTimelineStages,
		NcButton,
		NcDialog,
		NcTextArea,
	},
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
			showCoiDialog: false,
			coiReason: '',
			showMotionLinkDialog: false,
			availableMotions: [],
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('agenda-item')
		},
		hasBobPhase() {
			return ['discussion', 'decision'].includes(this.object.itemType)
		},
		currentBobStageIndex() {
			const status = this.object.status ?? 'beeldvorming'
			const idx = BOB_STAGES.findIndex(s => s.id === status)
			return idx === -1 ? 0 : idx
		},
		bobStages() {
			return BOB_STAGES.map(s => ({ ...s, label: this.t('decidesk', s.label) }))
		},
		coiCount() {
			return (this.object?.notes ?? []).filter(n => (n.title ?? '').startsWith('COI:')).length
		},
		spokespersonName() {
			return this.object?.relations?.spokesperson?.[0]?.displayName ?? null
		},
		linkedMotions() {
			return this.object?.relations?.motion ?? []
		},
		itemTypeLabel() {
			const map = {
				informational: this.t('decidesk', 'Informational'),
				discussion: this.t('decidesk', 'Discussion'),
				decision: this.t('decidesk', 'Decision'),
			}
			return map[this.object.itemType] ?? this.object.itemType ?? ''
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Type'), value: this.itemTypeLabel },
				{ label: this.t('decidesk', 'Order'), value: this.object.orderNumber },
				{ label: this.t('decidesk', 'Estimated Duration'), value: this.object.estimatedDuration ? `${this.object.estimatedDuration} min` : '' },
				{ label: this.t('decidesk', 'Actual Duration'), value: this.object.actualDuration ? `${this.object.actualDuration} min` : '' },
				{ label: this.t('decidesk', 'Description'), value: this.object.description },
				{ label: this.t('decidesk', 'Recurring'), value: this.object.isRecurring ? this.t('decidesk', 'Yes') : this.t('decidesk', 'No') },
				{ label: this.t('decidesk', 'Status'), value: this.object.status },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('agenda-item', this.id)
		},

		async submitCoi() {
			const displayName = OC?.currentUser?.displayName ?? OC?.currentUser ?? 'Unknown'
			try {
				// COI note stored via OpenRegister built-in notes API on the AgendaItem object.
				await fetch(
					OC.generateUrl(`/apps/openregister/api/objects/${this.id}/notes`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							title: `COI: ${displayName}`,
							content: this.coiReason,
						}),
					},
				)
				this.coiReason = ''
				this.showCoiDialog = false
				await this.objectStore.fetchObject('agenda-item', this.id)
			} catch (e) {
				console.error('Failed to submit COI declaration:', e)
			}
		},

		async loadAvailableMotions() {
			try {
				const meetingId = this.object?.relations?.meeting?.[0]?.id
				if (!meetingId) return
				const motions = await this.objectStore.fetchObjects('motion', {
					'@self.relations.meeting': meetingId,
				})
				this.availableMotions = motions ?? []
			} catch (e) {
				console.error('Failed to load motions:', e)
			}
		},

		async linkMotion(motion) {
			try {
				const existing = this.object?.relations?.motion ?? []
				await this.objectStore.saveObject('agenda-item', {
					...this.object,
					relations: {
						...(this.object.relations ?? {}),
						motion: [...existing, { id: motion.id }],
					},
				})
				await this.objectStore.fetchObject('agenda-item', this.id)
				this.showMotionLinkDialog = false
			} catch (e) {
				console.error('Failed to link motion:', e)
			}
		},
	},

	created() {
		this.loadAvailableMotions()
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

.agenda-item-detail__type-badge {
	margin-bottom: var(--default-grid-baseline);
}

.agenda-item-detail__coi-count {
	color: var(--color-error);
	font-size: calc(var(--default-font-size) * 0.875);
	margin-top: var(--default-grid-baseline);
}
</style>
