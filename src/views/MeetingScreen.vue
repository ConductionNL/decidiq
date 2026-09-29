<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The room screen of a live meeting (live-meeting-shared-current-item): for the
 projector in the council chamber. Shows the meeting, the agenda item the
 chair made current and, while a vote on it is open, that voting is open.
 Follows the meeting every few seconds; it has no controls.

 @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote
-->
<template>
	<div
		class="meeting-screen"
		role="main"
		data-testid="meeting-screen"
		:aria-label="t('decidiq', 'Room screen')">
		<p class="meeting-screen__meeting">
			{{ meeting.title || t('decidiq', 'Meeting') }}
		</p>
		<template v-if="currentItem">
			<p class="meeting-screen__label">
				{{ t('decidiq', 'Now on the agenda') }}
			</p>
			<h2 class="meeting-screen__item" data-testid="meeting-screen-item">
				<span v-if="currentItem.orderNumber">{{ currentItem.orderNumber }}.</span>
				{{ currentItem.title }}
			</h2>
			<p
				v-if="openRound"
				class="meeting-screen__vote"
				role="status"
				data-testid="meeting-screen-vote-open">
				{{ t('decidiq', 'Voting is open') }}
			</p>
		</template>
		<p v-else class="meeting-screen__label" data-testid="meeting-screen-no-item">
			{{ t('decidiq', 'No agenda item is being dealt with yet.') }}
		</p>
	</div>
</template>

<script>
import { useObjectStore } from '../store/store.js'
import {
	FOLLOW_INTERVAL_MS,
	openRoundFor,
	sharedCurrentItemId,
} from '../utils/liveMeeting.js'
import { matching, relationFilterFor } from '../utils/objectRelations.js'

export default {
	name: 'MeetingScreen',

	props: {
		id: { type: String, required: true },
	},

	/** @spec exclude setup() only wires the shared object store ref; no domain logic */
	setup() {
		return { objectStore: useObjectStore() }
	},

	data() {
		return {
			motions: [],
			rounds: [],
			timer: null,
		}
	},

	computed: {
		/** @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote */
		meeting() {
			return this.objectStore.objects?.meeting?.[this.id] ?? {}
		},

		/** @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote */
		currentItem() {
			const itemId = sharedCurrentItemId(this.meeting)
			if (!itemId) return null
			return this.objectStore.objects?.['agenda-item']?.[itemId] ?? null
		},

		/** @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote */
		openRound() {
			return openRoundFor(this.rounds, this.currentItem, this.motions)
		},
	},

	/** @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote */
	async created() {
		await this.refresh()
		this.timer = setInterval(() => this.refresh(), FOLLOW_INTERVAL_MS)
	},

	/** @spec exclude lifecycle teardown; only clears the follow timer */
	beforeUnmount() {
		if (this.timer) clearInterval(this.timer)
		this.timer = null
	},

	methods: {
		/**
		 * Re-read the meeting, its current item and the votes on it.
		 *
		 * @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote
		 */
		async refresh() {
			try {
				await this.objectStore.fetchObject('meeting', this.id)
				const itemId = sharedCurrentItemId(this.meeting)
				if (!itemId) return
				await this.objectStore.fetchObject('agenda-item', itemId)
				this.motions = matching(
					await this.objectStore.fetchCollection('motion', {
						...relationFilterFor(itemId),
						_limit: 50,
					}),
					itemId,
				)
				const targets = [itemId, ...this.motions.map((motion) => motion.id)]
				const pages = await Promise.all(
					targets.map((target) =>
						this.objectStore.fetchCollection('voting-round', {
							...relationFilterFor(target),
							_limit: 50,
						}),
					),
				)
				this.rounds = pages.flatMap((page, index) => matching(page, targets[index]))
			} catch (e) {
				// The next tick tries again; the screen keeps what it showed.
			}
		},
	},
}
</script>

<style scoped>
.meeting-screen {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: calc(var(--default-grid-baseline) * 4);
	min-height: 80vh;
	padding: calc(var(--default-grid-baseline) * 8);
	text-align: center;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.meeting-screen__meeting,
.meeting-screen__label {
	font-size: 1.5rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.meeting-screen__item {
	font-size: 3rem;
	line-height: 1.2;
	margin: 0;
}

.meeting-screen__vote {
	font-size: 2rem;
	font-weight: 700;
	color: var(--color-primary-element);
	margin: 0;
}
</style>
