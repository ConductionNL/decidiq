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
	<div
		class="live-meeting"
		role="main"
		data-testid="meeting-live"
		:aria-label="t('decidesk', 'Live meeting view')">
		<NcLoadingIcon v-if="loading" :size="64" />

		<template v-else>
			<!-- Meeting header -->
			<div class="live-meeting__header" data-testid="meeting-live-header">
				<h2 class="live-meeting__title">
					{{ meeting.title || t('decidesk', 'Live meeting') }}
				</h2>
				<CnStatusBadge :status="meeting.lifecycle || 'opened'" />
				<NcButton
					data-testid="meeting-live-back"
					:aria-label="t('decidesk', 'Back to meeting detail')"
					@click="$router.push({ name: 'MeetingDetail', params: { id } })">
					← {{ t('decidesk', 'Back') }}
				</NcButton>
			</div>

			<!-- Meeting cost panel (meeting-efficiency) -->
			<MeetingCostPanel
				:meeting="meeting"
				:participants="participants"
				:hourlyRate="hourlyRate" />

			<!-- Hamerstukken section -->
			<section
				v-if="hamerstukken.length > 0"
				class="live-meeting__hamerstukken"
				:aria-label="t('decidesk', 'Consent agenda items')">
				<h3>{{ t('decidesk', 'Consent agenda items (hamerstukken)') }}</h3>
				<ul class="live-meeting__hamerstukken-list" role="list">
					<li
						v-for="item in hamerstukken"
						:key="item.id"
						class="live-meeting__hamerstukken-item"
						role="listitem">
						<span>{{ item.orderNumber }}. {{ item.title }}</span>
						<NcButton
							v-if="isChair"
							size="small"
							:aria-label="
								t('decidesk', 'Remove {title} from consent agenda', {
									title: item.title,
								})
							"
							@click="removeFromHamerstukken(item)">
							{{ t('decidesk', 'Remove from consent agenda') }}
						</NcButton>
					</li>
				</ul>
				<NcButton
					v-if="isChair"
					variant="primary"
					data-testid="meeting-live-adopt-consent"
					:loading="processingHamerstukken"
					:aria-label="t('decidesk', 'Adopt all consent agenda items')"
					@click="confirmHamerstukken = true">
					{{ t('decidesk', 'Adopt consent agenda') }}
				</NcButton>

				<!-- Confirmation dialog (own file per modal-isolation, ADR-004) -->
				<AdoptConsentAgendaDialog
					v-if="confirmHamerstukken"
					:count="hamerstukken.length"
					:processing="processingHamerstukken"
					@confirm="processHamerstukken"
					@close="confirmHamerstukken = false" />
			</section>

			<!-- Regular agenda items -->
			<section
				class="live-meeting__items"
				:aria-label="t('decidesk', 'Agenda items')">
				<h3>{{ t('decidesk', 'Agenda items') }}</h3>

				<!-- Chair view: full edit controls -->
				<template v-if="isChair">
					<AgendaBuilder
						:meetingId="id"
						:isChair="true"
						:lifecycle="meeting.lifecycle || 'opened'"
						:meetingType="meeting.meetingType || ''"
						:items="regularItems"
						:participants="participants"
						@reordered="refreshItems"
						@itemUpdated="refreshItems" />
				</template>

				<!-- Non-chair: read-only list -->
				<template v-else>
					<ol class="live-meeting__readonly-list" role="list">
						<li
							v-for="item in regularItems"
							:key="item.id"
							class="live-meeting__readonly-item"
							:class="{
								'live-meeting__readonly-item--active':
									activeItemId === item.id,
							}"
							role="listitem"
							:aria-current="
								activeItemId === item.id ? 'true' : undefined
							">
							<span
								class="live-meeting__item-order"
								aria-hidden="true"
								>{{ item.orderNumber }}</span
							>
							<CnStatusBadge :status="item.itemType" />
							<span class="live-meeting__item-title">{{
								item.title
							}}</span>
							<span
								v-if="item.estimatedDuration"
								class="live-meeting__item-duration">
								{{ item.estimatedDuration }}
								{{ t('decidesk', 'min') }}
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
				<h3>
					{{
						t('decidesk', 'Active: {title}', { title: activeItem.title })
					}}
				</h3>

				<!-- Agenda-item countdown timer (meeting-efficiency) -->
				<AgendaItemTimer
					:key="activeItem.id"
					:item="activeItem"
					:isChair="isChair"
					:objectStore="objectStore"
					@closed="refreshItems" />

				<!-- BOB phase (discussion/decision only) -->
				<template
					v-if="['discussion', 'decision'].includes(activeItem.itemType)">
					<CnTimelineStages
						:stages="bobStages"
						:current="currentBobStageIndex(activeItem)"
						:aria-label="
							t('decidesk', 'BOB phase for {title}', {
								title: activeItem.title,
							})
						" />
					<NcButton
						v-if="isChair && canAdvanceBob(activeItem)"
						:loading="advancingBob"
						:aria-label="
							t('decidesk', 'Advance to next BOB phase for {title}', {
								title: activeItem.title,
							})
						"
						@click="advanceBobPhase(activeItem)">
						{{ t('decidesk', 'Next phase') }}
					</NcButton>
				</template>
			</section>

			<!-- Speaker queue (meeting-efficiency) -->
			<SpeakerQueuePanel
				v-if="activeItem"
				:meetingId="id"
				:participants="participants"
				:isChair="isChair" />

			<!-- Real-time minute taking (minutes-ui-v1) -->
			<MinutesPanel
				v-if="canTakeMinutes"
				:meetingId="id"
				:agendaItems="regularItems"
				:participants="participants" />

			<!-- Chair: activate item controls -->
			<section
				v-if="isChair"
				class="live-meeting__activate"
				:aria-label="t('decidesk', 'Activate agenda item')">
				<h4>{{ t('decidesk', 'Activate item') }}</h4>
				<ul class="live-meeting__activate-list" role="list">
					<li
						v-for="item in regularItems"
						:key="item.id"
						class="live-meeting__activate-item"
						role="listitem">
						<NcButton
							size="small"
							:variant="
								activeItemId === item.id ? 'primary' : 'secondary'
							"
							:aria-label="
								t('decidesk', 'Activate {title}', {
									title: item.title,
								})
							"
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
import { CnStatusBadge, CnTimelineStages } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AgendaBuilder from '../components/AgendaBuilder.vue'
import AgendaItemTimer from '../components/liveMeeting/AgendaItemTimer.vue'
import MeetingCostPanel from '../components/liveMeeting/MeetingCostPanel.vue'
import SpeakerQueuePanel from '../components/liveMeeting/SpeakerQueuePanel.vue'
import MinutesPanel from '../components/minutesEditor/MinutesPanel.vue'
import AdoptConsentAgendaDialog from '../dialogs/AdoptConsentAgendaDialog.vue'
import { useObjectStore } from '../store/store.js'

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
		NcLoadingIcon,
		CnStatusBadge,
		CnTimelineStages,
		AgendaBuilder,
		MinutesPanel,
		AdoptConsentAgendaDialog,
		AgendaItemTimer,
		SpeakerQueuePanel,
		MeetingCostPanel,
	},

	props: {
		id: { type: String, required: true },
	},

	/** @spec exclude setup() only wires the shared object store ref; no domain logic */
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},

	data() {
		return {
			loading: true,
			activeItemId: null,
			advancingBob: false,
			processingHamerstukken: false,
			confirmHamerstukken: false,
			// Live-update subscription handles, populated in created().
			// The lib's liveUpdatesPlugin auto-falls-back to polling
			// (30s/60s) when notify_push is unavailable, so the page
			// works on stacks with or without the WebSocket sidecar.
			liveSubs: [],
		}
	},

	computed: {
		// Read directly from the shared store cache so the
		// liveUpdatesPlugin's auto-refetch on `or-object-{uuid}` /
		// `or-collection-...` events propagates to the rendered UI
		// without per-component watcher boilerplate. Pre-migration
		// LiveMeeting copied data into local state in fetchData(),
		// which made the page non-reactive to live updates: the plugin
		// updated the store cache but the local copy never re-read.
		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
		meeting() {
			return this.objectStore.objects?.meeting?.[this.id] ?? {}
		},

		/**
		 * Agenda items belonging to this meeting.
		 *
		 * `meeting` is the AgendaItem schema's own property (a `$ref: Meeting`
		 * uuid), which is what the seed data and every other decidesk surface
		 * (MeetingAgendaTab, MeetingVotesTab) write and filter on. It is checked
		 * FIRST here; the two `relations` shapes stay as fallbacks for records
		 * where OpenRegister materialised the link into `@self.relations` but the
		 * scalar property was not round-tripped.
		 *
		 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1
		 */
		allItems() {
			const collection = this.objectStore.collections?.['agenda-item'] ?? []
			return collection.filter(
				(i) =>
					i?.meeting === this.id
					|| i?.['@self']?.relations?.meeting === this.id
					|| i?.relations?.meeting === this.id,
			)
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
		participants() {
			const collection = this.objectStore.collections?.participant ?? []
			return collection.filter(
				(p) =>
					p?.['@self']?.relations?.meeting === this.id
					|| p?.relations?.meeting === this.id,
			)
		},

		/**
		 * The linked governance body's hourly rate (EUR per attendee), used by
		 * the live cost panel. 0 when no body / no rate is configured.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		hourlyRate() {
			const bodyId =
				this.meeting?.governanceBody
				?? this.meeting?.['@self']?.relations?.governanceBody
			if (!bodyId) return 0
			const body = this.objectStore.objects?.['governance-body']?.[bodyId]
			const rate = Number(body?.hourlyRate)
			return Number.isFinite(rate) && rate > 0 ? rate : 0
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
		isChair() {
			const currentUser = getCurrentUser()
			if (!currentUser) return false
			// nextcloudUserId is the canonical link (ParticipantResolver);
			// owner is the legacy fallback for pre-migration records.
			return this.participants.some(
				(p) =>
					(p.nextcloudUserId === currentUser.uid
						|| (!p.nextcloudUserId && p.owner === currentUser.uid))
					&& p.role === 'chair',
			)
		},

		/**
		 * Whether the current user may take live minutes: secretary (the
		 * spec's primary actor), chair, or NC admin — mirrors the backend
		 * chair/secretary/admin guard on the minutes endpoints.
		 *
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		canTakeMinutes() {
			const currentUser = getCurrentUser()
			if (!currentUser) return false
			if (currentUser.isAdmin) return true
			return this.participants.some(
				(p) =>
					(p.nextcloudUserId === currentUser.uid
						|| (!p.nextcloudUserId && p.owner === currentUser.uid))
					&& ['chair', 'secretary'].includes(p.role),
			)
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.3 */
		bobStages() {
			return BOB_STAGES.map((s) => ({
				...s,
				label: this.t('decidesk', s.label),
			}))
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.4 */
		hamerstukken() {
			return this.allItems.filter((i) => (i.tags ?? []).includes('hamerstuk'))
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
		regularItems() {
			return this.allItems
				.filter((i) => !(i.tags ?? []).includes('hamerstuk'))
				.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.2 */
		activeItem() {
			return this.allItems.find((i) => i.id === this.activeItemId) ?? null
		},
	},

	/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
	async created() {
		await this.fetchData()

		// Live updates: subscribe to the meeting object + the two
		// collections the page renders. The store's subscribe() returns
		// a handle and auto-refetches the affected resource when an OR
		// notify_push event arrives. The plugin itself falls back to
		// coalesced polling (30s collections / 60s objects) when
		// notify_push is unavailable, so the page works on stacks with
		// or without the WebSocket sidecar with no extra config.
		//
		// Why these three:
		//   - meeting object: status / currentAgendaItem advance fires
		//     or-object-{meetingUuid}; the page picks up the new active
		//     agenda item and re-renders the chairperson controls.
		//   - agenda-item collection: a new vote object created mid-meeting,
		//     or an item removed/reordered, fires or-collection-... and
		//     the items list refreshes.
		//   - participant collection: late joiners / drop-offs surface
		//     immediately so the chair indicator stays accurate.
		this.liveSubs.push(await this.objectStore.subscribe('meeting', this.id))
		this.liveSubs.push(await this.objectStore.subscribe('agenda-item'))
		this.liveSubs.push(await this.objectStore.subscribe('participant'))
	},

	/** @spec exclude lifecycle teardown; only unsubscribes the live-update handles created in created() */
	beforeUnmount() {
		// Tear down all live-update subscriptions; refcount drops to 0 ->
		// the underlying notify_push listener for each event key is removed.
		for (const handle of this.liveSubs) {
			try {
				this.objectStore.unsubscribe(handle)
			} catch (e) {
				// best-effort cleanup
			}
		}
		this.liveSubs = []
	},

	methods: {
		/**
		 * @param item
		 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.3
		 */
		currentBobStageIndex(item) {
			const status = item?.status ?? 'beeldvorming'
			const idx = BOB_STAGES.findIndex((s) => s.id === status)
			return idx === -1 ? 0 : idx
		},

		/**
		 * @param item
		 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.3
		 */
		canAdvanceBob(item) {
			return item?.status !== BOB_FINAL && item?.itemType !== 'informational'
		},

		/**
		 * @param item
		 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.2
		 */
		activateItem(item) {
			this.activeItemId = item.id
		},

		/**
		 * @param item
		 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.3
		 */
		async advanceBobPhase(item) {
			this.advancingBob = true
			try {
				const response = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/agenda-items/${item.id}/bob-phase`,
					),
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

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.4 */
		async processHamerstukken() {
			this.processingHamerstukken = true
			this.confirmHamerstukken = false
			try {
				const response = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/agendas/${this.id}/hamerstukken`,
					),
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

		/**
		 * @param item
		 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4.4
		 */
		async removeFromHamerstukken(item) {
			const tags = (item.tags ?? []).filter((t) => t !== 'hamerstuk')
			try {
				await this.objectStore.saveObject('agenda-item', { ...item, tags })
				await this.refreshItems()
			} catch (e) {
				console.error('Error removing hamerstuk tag:', e)
			}
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
		async fetchData() {
			// Trigger initial fetches to populate the shared store cache.
			// We deliberately don't assign results to local state — the
			// `meeting`, `allItems`, and `participants` computed getters
			// read straight from the store, so when the liveUpdatesPlugin
			// re-fetches on `or-object-*` / `or-collection-*` events the
			// rendered UI updates automatically via Vue reactivity.
			// FILTER DIALECT (do not "restore" the dotted @self form): OpenRegister's
			// MagicSearchHandler classifies any query key that is not exactly `@self`,
			// not `_`-prefixed and not a reserved context param as an OBJECT-FIELD
			// filter. `@self.relations.meeting` is no schema property, so
			// applyObjectFilters() appends `1 = 0` — the collection came back EMPTY on
			// a perfectly healthy HTTP 200, which read as a broken agenda/minutes panel.
			// `meeting` IS the AgendaItem schema's property (see allItems above) and is
			// the dialect MeetingAgendaTab / MeetingVotesTab already use.
			//
			// Participant carries NO meeting/meetings property at all (see
			// decidesk_register.json), so no server-side meeting filter is expressible
			// for it — any such key would be another silent `1 = 0`. Fetch a bounded
			// page and let the `participants` computed do the meeting scoping, exactly
			// as MeetingParticipantsTab does.
			try {
				await Promise.all([
					this.objectStore.fetchObject('meeting', this.id),
					this.objectStore.fetchCollection('agenda-item', {
						meeting: this.id,
						_limit: 200,
					}),
					this.objectStore.fetchCollection('participant', {
						_limit: 200,
					}),
				])
				// Meeting-efficiency: lazily fetch the linked governance body so
				// the live cost panel can read its hourlyRate. Best-effort — the
				// panel renders a no-rate hint when the body / rate is absent.
				await this.fetchGovernanceBody()
			} catch (e) {
				console.error('Error fetching live meeting data:', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the meeting's linked governance body (for the cost panel's
		 * hourlyRate). Best-effort; failures are non-fatal.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		async fetchGovernanceBody() {
			const bodyId =
				this.meeting?.governanceBody
				?? this.meeting?.['@self']?.relations?.governanceBody
			if (!bodyId) return
			try {
				await this.objectStore.fetchObject('governance-body', bodyId)
			} catch (e) {
				console.error('Error fetching governance body:', e)
			}
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-4.1 */
		async refreshItems() {
			try {
				// Same dialect as fetchData() — see the note there on why the
				// dotted `@self.relations.…` key silently returns zero rows.
				await this.objectStore.fetchCollection('agenda-item', {
					meeting: this.id,
					_limit: 200,
				})
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
