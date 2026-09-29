<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Speaker queue panel (meeting-efficiency / speaking time management).

 Maintains an ordered speaker queue for the active discussion: an NcSelect
 speaker picker (request to speak), chair up/down reorder and remove, a
 configurable per-speaker time limit with an over-limit alert, and the current
 speaker highlighted with a running timer. Stopping (or switching) a speaker
 records the speech via the existing POST /api/engagement endpoint so the
 speakingDuration lands in the EngagementRecord aggregate. The 1-second
 interval lives here; all queue decisions live in src/utils/speakerQueue.js
 (pure, vitest-covered).

 @spec openspec/specs/meeting-efficiency/spec.md
-->
<template>
	<section
		class="speaker-queue"
		data-testid="speaker-queue-panel"
		:aria-label="t('decidiq', 'Speaker queue')">
		<div class="speaker-queue__header">
			<h4 class="speaker-queue__title">
				{{ t('decidiq', 'Speaker queue') }}
			</h4>
			<div v-if="isChair" class="speaker-queue__limit">
				<label class="speaker-queue__limit-label" for="speaker-queue-limit">
					{{ t('decidiq', 'Speaking limit (min)') }}
				</label>
				<NcTextField
					id="speaker-queue-limit"
					v-model="limitMinutes"
					type="number"
					:label="t('decidiq', 'Speaking limit (min)')"
					data-testid="speaker-queue-limit-input"
					min="0" />
			</div>
		</div>

		<div v-if="isChair" class="speaker-queue__add">
			<NcSelect
				v-model="selectedParticipant"
				:options="participantOptions"
				:inputLabel="t('decidiq', 'Add speaker to queue')"
				:placeholder="t('decidiq', 'Select a participant')"
				label="label"
				data-testid="speaker-queue-add-select" />
			<NcButton
				variant="primary"
				:disabled="!selectedParticipant"
				data-testid="speaker-queue-add-button"
				:aria-label="t('decidiq', 'Add to queue')"
				@click="addSelected">
				{{ t('decidiq', 'Add to queue') }}
			</NcButton>
		</div>

		<ol
			v-if="queue.length"
			class="speaker-queue__list"
			role="list"
			data-testid="speaker-queue-list">
			<li
				v-for="(entry, idx) in queue"
				:key="entry.participantId"
				class="speaker-queue__item"
				:class="{
					'speaker-queue__item--speaking': entry.speaking,
					'speaker-queue__item--over': entry.speaking && overLimit(entry),
				}"
				role="listitem"
				:aria-current="entry.speaking ? 'true' : undefined"
				:data-testid="
					entry.speaking
						? 'speaker-queue-current'
						: 'speaker-queue-waiting'
				">
				<span class="speaker-queue__order" aria-hidden="true">{{
					idx + 1
				}}</span>
				<span class="speaker-queue__name">{{ entry.displayName }}</span>
				<span
					class="speaker-queue__elapsed"
					:class="{
						'speaker-queue__elapsed--over':
							entry.speaking && overLimit(entry),
					}">
					{{ elapsedText(entry) }}
				</span>
				<span
					v-if="entry.speaking && overLimit(entry)"
					class="speaker-queue__over-tag"
					role="alert"
					data-testid="speaker-queue-over-limit">
					{{ t('decidiq', 'Over limit') }}
				</span>
				<template v-if="isChair">
					<NcButton
						v-if="!entry.speaking"
						size="small"
						data-testid="speaker-queue-give-floor"
						:aria-label="
							t('decidiq', 'Give floor to {name}', {
								name: entry.displayName,
							})
						"
						@click="giveFloor(entry.participantId)">
						{{ t('decidiq', 'Give floor') }}
					</NcButton>
					<NcButton
						v-else
						size="small"
						variant="secondary"
						data-testid="speaker-queue-stop"
						:aria-label="
							t('decidiq', 'Stop {name}', { name: entry.displayName })
						"
						@click="stop">
						{{ t('decidiq', 'Stop') }}
					</NcButton>
					<NcButton
						size="small"
						:disabled="idx === 0"
						:aria-label="
							t('decidiq', 'Move {name} up', {
								name: entry.displayName,
							})
						"
						@click="move(entry.participantId, -1)">
						↑
					</NcButton>
					<NcButton
						size="small"
						:disabled="idx === queue.length - 1"
						:aria-label="
							t('decidiq', 'Move {name} down', {
								name: entry.displayName,
							})
						"
						@click="move(entry.participantId, 1)">
						↓
					</NcButton>
					<NcButton
						size="small"
						variant="secondary"
						data-testid="speaker-queue-question"
						:aria-label="
							t('decidiq', 'Log a question by {name}', {
								name: entry.displayName,
							})
						"
						@click="recordQuestion(entry)">
						{{ t('decidiq', 'Question raised') }}
					</NcButton>
					<NcButton
						size="small"
						variant="tertiary"
						data-testid="speaker-queue-remove"
						:aria-label="
							t('decidiq', 'Remove {name} from queue', {
								name: entry.displayName,
							})
						"
						@click="remove(entry.participantId)">
						✕
					</NcButton>
				</template>
			</li>
		</ol>
		<p v-else class="speaker-queue__empty" data-testid="speaker-queue-empty">
			{{ t('decidiq', 'No speakers in the queue.') }}
		</p>

		<div
			v-if="contributions.length"
			class="speaker-queue__contributions"
			data-testid="speaker-queue-contributions">
			<h5 class="speaker-queue__contributions-title">
				{{ t('decidiq', 'Contributions on this item') }}
			</h5>
			<ul>
				<li
					v-for="(contribution, idx) in contributions"
					:key="`${contribution.participantId}-${contribution.kind}-${idx}`">
					{{
						contribution.kind === 'speech'
							? t('decidiq', '{name} spoke for {time}', {
								name: contribution.name,
								time: durationLabel(contribution.duration),
							})
							: t('decidiq', '{name} raised a question', {
								name: contribution.name,
							})
					}}
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { contributionsOn, engagementBody } from '../../utils/liveMeeting.js'
import { formatClock } from '../../utils/meetingTimer.js'
import {
	addSpeaker,
	isOverLimit,
	moveSpeaker,
	removeSpeaker,
	speakerElapsedSeconds,
	startSpeaker,
	stopSpeaker,
} from '../../utils/speakerQueue.js'

/**
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export default {
	name: 'SpeakerQueuePanel',

	components: { NcButton, NcSelect, NcTextField },

	props: {
		meetingId: { type: String, required: true },
		// The agenda item being dealt with; speeches and questions carry it
		// (live-meeting-shared-current-item).
		currentItemId: { type: String, default: null },
		participants: { type: Array, default: () => [] },
		isChair: { type: Boolean, default: false },
	},

	data() {
		return {
			queue: [],
			selectedParticipant: null,
			limitMinutes: 3,
			now: Date.now(),
			intervalId: null,
			// This meeting's engagement records, for the contributions on
			// the current item (live-meeting-shared-current-item).
			records: [],
		}
	},

	computed: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		limitSeconds() {
			const m = Number(this.limitMinutes)
			return Number.isFinite(m) && m > 0 ? m * 60 : null
		},

		/**
		 * Who spoke and who raised a question on the current item.
		 *
		 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
		 */
		contributions() {
			return contributionsOn(this.records, this.currentItemId, this.participants)
		},

		/** @spec openspec/specs/meeting-efficiency/spec.md */
		participantOptions() {
			const queued = new Set(this.queue.map((e) => e.participantId))
			return this.participants
				.filter((p) => !queued.has(p.id))
				.map((p) => ({ id: p.id, label: p.displayName || p.name || p.id }))
		},
	},

	watch: {
		/**
		 * Read the contributions again when the chair moves to another item.
		 *
		 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
		 */
		currentItemId: {
			handler() {
				this.loadRecords()
			},
			immediate: true,
		},
	},

	/** @spec exclude lifecycle hook; starts the 1s render tick only */
	mounted() {
		this.intervalId = setInterval(() => {
			this.now = Date.now()
		}, 1000)
	},

	/** @spec exclude lifecycle teardown; clears the render interval */
	beforeUnmount() {
		if (this.intervalId) clearInterval(this.intervalId)
	},

	methods: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		addSelected() {
			if (!this.selectedParticipant) return
			this.queue = addSpeaker(
				this.queue,
				{
					id: this.selectedParticipant.id,
					displayName: this.selectedParticipant.label,
				},
				Date.now(),
			)
			this.selectedParticipant = null
		},

		/**
		 * @param participantId
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		remove(participantId) {
			this.queue = removeSpeaker(this.queue, participantId)
		},

		/**
		 * @param participantId
		 * @param direction
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		move(participantId, direction) {
			this.queue = moveSpeaker(this.queue, participantId, direction)
		},

		/**
		 * @param participantId
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		giveFloor(participantId) {
			const { queue, stopped } = startSpeaker(
				this.queue,
				participantId,
				Date.now(),
			)
			this.queue = queue
			if (stopped) this.recordSpeech(stopped)
		},

		/** @spec openspec/specs/meeting-efficiency/spec.md */
		stop() {
			const { queue, stopped } = stopSpeaker(this.queue, Date.now())
			this.queue = queue
			if (stopped) this.recordSpeech(stopped)
		},

		/**
		 * @param entry
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		elapsedText(entry) {
			return formatClock(speakerElapsedSeconds(entry, this.now))
		},

		/**
		 * @param entry
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		overLimit(entry) {
			return isOverLimit(entry, this.limitSeconds, this.now)
		},

		/**
		 * Persist a completed speech to the EngagementRecord aggregate via the
		 * existing engagement endpoint (server-side chair/secretary/admin guard).
		 *
		 * @param {{participantId: string, durationSeconds: number}} stopped The recorded speech.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
		 */
		async recordSpeech(stopped) {
			if (!stopped || stopped.durationSeconds <= 0) return
			try {
				await fetch(OC.generateUrl('/apps/decidiq/api/engagement'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(
						engagementBody(
							this.meetingId,
							stopped.participantId,
							'speech',
							this.currentItemId,
							{ duration: stopped.durationSeconds },
						),
					),
				})
				this.$emit('speech-recorded', stopped)
				await this.loadRecords()
			} catch (e) {
				console.error('Failed to record speech:', e)
			}
		},

		/**
		 * Log that a member raised a question on the current item.
		 *
		 * @param {{participantId: string}} entry The queue entry.
		 *
		 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
		 */
		async recordQuestion(entry) {
			try {
				await fetch(OC.generateUrl('/apps/decidiq/api/engagement'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(
						engagementBody(
							this.meetingId,
							entry.participantId,
							'question',
							this.currentItemId,
						),
					),
				})
				await this.loadRecords()
			} catch (e) {
				console.error('Failed to record question:', e)
			}
		},

		/**
		 * A speech's length as m:ss.
		 *
		 * @param {number} seconds The duration.
		 *
		 * @return {string} The clock text.
		 *
		 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
		 */
		durationLabel(seconds) {
			return formatClock(seconds)
		},

		/**
		 * Read this meeting's engagement records; the server narrows them to
		 * the caller's own unless the caller chairs or takes the minutes.
		 *
		 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
		 */
		async loadRecords() {
			try {
				const response = await fetch(
					OC.generateUrl('/apps/decidiq/api/engagement')
						+ '?meeting=' + encodeURIComponent(this.meetingId),
					{ headers: { requesttoken: OC.requestToken } },
				)
				const data = response.ok ? await response.json() : {}
				this.records = Array.isArray(data?.records) ? data.records : []
			} catch (e) {
				this.records = []
			}
		},
	},
}
</script>

<style scoped>
.speaker-queue {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.speaker-queue__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}

.speaker-queue__title {
	margin: 0;
}

.speaker-queue__limit {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.speaker-queue__limit-label {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.speaker-queue__add {
	display: flex;
	align-items: flex-end;
	gap: var(--default-grid-baseline);
	margin-block: var(--default-grid-baseline);
}

.speaker-queue__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.speaker-queue__item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.speaker-queue__item:last-child {
	border-bottom: none;
}

.speaker-queue__item--speaking {
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	padding-inline: var(--default-grid-baseline);
}

.speaker-queue__item--over {
	background: var(--color-error-hover, var(--color-background-hover));
}

.speaker-queue__order {
	font-weight: 700;
	min-width: 1.5rem;
	text-align: right;
	color: var(--color-text-maxcontrast);
}

.speaker-queue__name {
	flex: 1;
}

.speaker-queue__elapsed {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.speaker-queue__elapsed--over {
	color: var(--color-error);
}

.speaker-queue__over-tag {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: var(--border-radius);
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.speaker-queue__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.speaker-queue__contributions-title {
	margin: 0 0 calc(var(--default-grid-baseline) * 1);
}

.speaker-queue__contributions ul {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}
</style>
