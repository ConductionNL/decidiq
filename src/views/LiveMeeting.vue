<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1
 @spec openspec/changes/p2-agenda-management/tasks.md#task-4.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-4.3
 @spec openspec/changes/p2-agenda-management/tasks.md#task-4.4
 @spec openspec/changes/p2-agenda-management/tasks.md#task-4.5
-->
<template>
	<div class="live-meeting" role="main" :aria-label="t('decidesk', 'Live meeting view')">
		<NcLoadingIcon v-if="loading" :size="64" />

		<template v-else>
			<!-- Meeting header -->
			<div class="live-meeting__header">
				<h2 class="live-meeting__title">
					{{ meeting.title || t('decidesk', 'Live meeting') }}
				</h2>
				<CnStatusBadge :status="meeting.lifecycle || 'opened'" />
				<NcButton
					:aria-label="t('decidesk', 'Back to meeting detail')"
					@click="$router.push({ name: 'MeetingDetail', params: { id } })">
					← {{ t('decidesk', 'Back') }}
				</NcButton>
			</div>

			<!-- Hamerstukken section -->
			<section
				v-if="hamerstukken.length > 0"
				class="live-meeting__hamerstukken"
				:aria-label="t('decidesk', 'Consent agenda items')">
				<h3>{{ t('decidesk', 'Consent agenda items (hamerstukken)') }}</h3>
				<ul class="live-meeting__hamerstukken-list" role="list">
					<li v-for="item in hamerstukken"
						:key="item.id"
						class="live-meeting__hamerstukken-item"
						role="listitem">
						<span>{{ item.orderNumber }}. {{ item.title }}</span>
						<NcButton
							v-if="isChair"
							size="small"
							:aria-label="t('decidesk', 'Remove {title} from consent agenda', { title: item.title })"
							@click="removeFromHamerstukken(item)">
							{{ t('decidesk', 'Remove from consent agenda') }}
						</NcButton>
					</li>
				</ul>
				<NcButton
					v-if="isChair"
					type="primary"
					:loading="processingHamerstukken"
					:aria-label="t('decidesk', 'Adopt all consent agenda items')"
					@click="confirmHamerstukken = true">
					{{ t('decidesk', 'Adopt consent agenda') }}
				</NcButton>

				<!-- Confirmation dialog -->
				<NcDialog
					v-if="confirmHamerstukken"
					:name="t('decidesk', 'Confirm adoption')"
					@closing="confirmHamerstukken = false">
					<template #default>
						<p>{{ t('decidesk', 'This will set all {n} consent agenda items to "Adopted" (afgerond). Continue?', { n: hamerstukken.length }) }}</p>
					</template>
					<template #actions>
						<NcButton type="primary" :loading="processingHamerstukken" @click="processHamerstukken">
							{{ t('decidesk', 'Confirm') }}
						</NcButton>
						<NcButton @click="confirmHamerstukken = false">
							{{ t('decidesk', 'Cancel') }}
						</NcButton>
					</template>
				</NcDialog>
			</section>

			<!-- Regular agenda items -->
			<section class="live-meeting__items" :aria-label="t('decidesk', 'Agenda items')">
				<h3>{{ t('decidesk', 'Agenda items') }}</h3>

				<!-- Chair view: full edit controls -->
				<template v-if="isChair">
					<AgendaBuilder
						:meeting-id="id"
						:is-chair="true"
						:lifecycle="meeting.lifecycle || 'opened'"
						:items="regularItems"
						:participants="participants"
						@reordered="refreshItems"
						@item-updated="refreshItems" />
				</template>

				<!-- Non-chair: read-only list -->
				<template v-else>
					<ol class="live-meeting__readonly-list" role="list">
						<li v-for="item in regularItems"
							:key="item.id"
							class="live-meeting__readonly-item"
							:class="{ 'live-meeting__readonly-item--active': activeItemId === item.id }"
							role="listitem"
							:aria-current="activeItemId === item.id ? 'true' : undefined">
							<span class="live-meeting__item-order" aria-hidden="true">{{ item.orderNumber }}</span>
							<CnStatusBadge :status="item.itemType" />
							<span class="live-meeting__item-title">{{ item.title }}</span>
							<span v-if="item.estimatedDuration" class="live-meeting__item-duration">
								{{ item.estimatedDuration }} {{ t('decidesk', 'min') }}
							</span>
						</li>
					</ol>
				</template>
			</section>

			<!-- Active item + BOB phase panel -->
			<section
				v-if="activeItem"
				class="live-meeting__active"
				:aria-label="t('decidesk', 'Active agenda item')">
				<h3>{{ t('decidesk', 'Active: {title}', { title: activeItem.title }) }}</h3>

				<!-- BOB phase (discussion/decision only) -->
				<template v-if="['discussion', 'decision'].includes(activeItem.itemType)">
					<CnTimelineStages
						:stages="bobStages"
						:current="currentBobStageIndex(activeItem)"
						:aria-label="t('decidesk', 'BOB phase for {title}', { title: activeItem.title })" />
					<NcButton
						v-if="isChair && canAdvanceBob(activeItem)"
						:loading="advancingBob"
						:aria-label="t('decidesk', 'Advance to next BOB phase for {title}', { title: activeItem.title })"
						@click="advanceBobPhase(activeItem)">
						{{ t('decidesk', 'Next phase') }}
					</NcButton>
				</template>
			</section>

			<!-- Chair: activate item controls -->
			<section
				v-if="isChair"
				class="live-meeting__activate"
				:aria-label="t('decidesk', 'Activate agenda item')">
				<h4>{{ t('decidesk', 'Activate item') }}</h4>
				<ul class="live-meeting__activate-list" role="list">
					<li v-for="item in regularItems"
						:key="item.id"
						class="live-meeting__activate-item"
						role="listitem">
						<NcButton
							size="small"
							:type="activeItemId === item.id ? 'primary' : 'secondary'"
							:aria-label="t('decidesk', 'Activate {title}', { title: item.title })"
							:aria-pressed="activeItemId === item.id"
							@click="activateItem(item)">
							{{ item.orderNumber }}. {{ item.title }}
						</NcButton>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { CnStatusBadge, CnTimelineStages } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { useObjectStore } from '../store/store.js'
import AgendaBuilder from '../components/AgendaBuilder.vue'

const BOB_STAGES = [
	{ id: 'beeldvorming', label: 'Beeldvorming' },
	{ id: 'oordeelsvorming', label: 'Oordeelsvorming' },
	{ id: 'besluitvorming', label: 'Besluitvorming' },
]

const BOB_FINAL = 'afgerond'

/**
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1
 */
export default {
	name: 'LiveMeeting',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		CnStatusBadge,
		CnTimelineStages,
		AgendaBuilder,
	},

	props: {
		id: { type: String, required: true },
	},

	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},

	data() {
		return {
			meeting: {},
			allItems: [],
			participants: [],
			loading: true,
			activeItemId: null,
			advancingBob: false,
			processingHamerstukken: false,
			confirmHamerstukken: false,
			refreshInterval: null,
		}
	},

	computed: {
		isChair() {
			const currentUser = getCurrentUser()
			if (!currentUser) return false
			return this.participants.some(
				p => p.owner === currentUser.uid && p.role === 'chair',
			)
		},

		bobStages() {
			return BOB_STAGES.map(s => ({ ...s, label: this.t('decidesk', s.label) }))
		},

		hamerstukken() {
			return this.allItems.filter(i => (i.tags ?? []).includes('hamerstuk'))
		},

		regularItems() {
			return this.allItems
				.filter(i => !(i.tags ?? []).includes('hamerstuk'))
				.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
		},

		activeItem() {
			return this.allItems.find(i => i.id === this.activeItemId) ?? null
		},
	},

	async created() {
		await this.fetchData()
		// Auto-refresh every 30 seconds.
		this.refreshInterval = setInterval(() => this.refreshItems(), 30000)
	},

	beforeDestroy() {
		if (this.refreshInterval !== null) {
			clearInterval(this.refreshInterval)
		}
	},

	methods: {
		currentBobStageIndex(item) {
			const status = item?.status ?? 'beeldvorming'
			const idx = BOB_STAGES.findIndex(s => s.id === status)
			return idx === -1 ? 0 : idx
		},

		canAdvanceBob(item) {
			return item?.status !== BOB_FINAL && item?.itemType !== 'informational'
		},

		activateItem(item) {
			this.activeItemId = item.id
		},

		async advanceBobPhase(item) {
			this.advancingBob = true
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/decidesk/api/agenda-items/${item.id}/bob-phase`),
					{
						method: 'PUT',
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (!response.ok) {
					console.error('Failed to advance BOB phase')
					return
				}
				await this.refreshItems()
			} catch (e) {
				console.error('Error advancing BOB phase:', e)
			} finally {
				this.advancingBob = false
			}
		},

		async processHamerstukken() {
			this.processingHamerstukken = true
			this.confirmHamerstukken = false
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/decidesk/api/agendas/${this.id}/hamerstukken`),
					{
						method: 'POST',
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (!response.ok) {
					console.error('Failed to process hamerstukken:', response.status)
					return
				}
				await this.refreshItems()
			} catch (e) {
				console.error('Error processing hamerstukken:', e)
			} finally {
				this.processingHamerstukken = false
			}
		},

		async removeFromHamerstukken(item) {
			const tags = (item.tags ?? []).filter(t => t !== 'hamerstuk')
			try {
				await this.objectStore.saveObject('agenda-item', { ...item, tags })
				await this.refreshItems()
			} catch (e) {
				console.error('Error removing hamerstuk tag:', e)
			}
		},

		async fetchData() {
			try {
				const meeting = await this.objectStore.fetchObject('meeting', this.id)
				this.meeting = meeting ?? {}
				const items = await this.objectStore.fetchObjects('agenda-item', {
					'@self.relations.meeting': this.id,
				})
				this.allItems = items ?? []
				const parts = await this.objectStore.fetchObjects('participant', {
					'@self.relations.meeting': this.id,
				})
				this.participants = parts ?? []
			} catch (e) {
				console.error('Error fetching live meeting data:', e)
			} finally {
				this.loading = false
			}
		},

		async refreshItems() {
			try {
				const items = await this.objectStore.fetchObjects('agenda-item', {
					'@self.relations.meeting': this.id,
				})
				this.allItems = items ?? []
			} catch (e) {
				console.error('Error refreshing items:', e)
			}
		},
	},
}
</script>

<style scoped>
.live-meeting {
	padding: var(--default-grid-baseline);
	max-width: 60rem;
	margin: 0 auto;
}

.live-meeting__header {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.live-meeting__title {
	margin: 0;
}

.live-meeting__hamerstukken {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
}

.live-meeting__hamerstukken-list,
.live-meeting__activate-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.live-meeting__hamerstukken-item,
.live-meeting__activate-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.live-meeting__hamerstukken-item:last-child,
.live-meeting__activate-item:last-child {
	border-bottom: none;
}

.live-meeting__items,
.live-meeting__active,
.live-meeting__activate {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.live-meeting__readonly-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.live-meeting__readonly-item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.live-meeting__readonly-item--active {
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	padding-left: var(--default-grid-baseline);
}

.live-meeting__readonly-item:last-child {
	border-bottom: none;
}

.live-meeting__item-order {
	font-weight: 700;
	min-width: 2rem;
	text-align: right;
	color: var(--color-text-maxcontrast);
}

.live-meeting__item-title {
	flex: 1;
}

.live-meeting__item-duration {
	color: var(--color-text-maxcontrast);
	font-size: calc(var(--default-font-size) * 0.875);
}
</style>
