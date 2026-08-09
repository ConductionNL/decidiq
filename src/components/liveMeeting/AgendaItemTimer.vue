<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Agenda-item countdown timer (meeting-efficiency / agenda item timer).

 Renders a countdown for the active agenda item from its estimatedDuration.
 Chair-only controls: Start / Pause / Resume / Extend +5 / +10 / Close item.
 Over-time = red pulsing clock + role="alert" with the extend/close options.
 Informational items (no estimatedDuration) show no countdown but still track
 elapsed time, which is written back on close. On Close, actualDuration (and
 pausedDuration, recorded separately) are persisted to the agenda-item through
 the shared OR object store — per-object ACLs are the server-side enforcement,
 so no new endpoint is needed. The 1-second interval lives here; all decisions
 live in src/utils/meetingTimer.js (pure, vitest-covered).

 @spec openspec/specs/meeting-efficiency/spec.md
-->
<template>
	<div class="agenda-timer" data-testid="agenda-item-timer">
		<div
			v-if="hasAllocation"
			class="agenda-timer__clock"
			:class="{ 'agenda-timer__clock--over': overTime, 'agenda-timer__clock--paused': isPaused }"
			:role="overTime ? 'alert' : undefined"
			:aria-label="t('decidesk', 'Time remaining for {title}', { title: item.title })"
			data-testid="agenda-item-timer-clock">
			{{ clockText }}
			<span v-if="isPaused" class="agenda-timer__paused-tag" data-testid="agenda-item-timer-paused">
				{{ t('decidesk', 'Paused') }}
			</span>
			<span v-else-if="overTime" class="agenda-timer__over-tag">
				{{ t('decidesk', 'Over time') }}
			</span>
		</div>
		<p v-else class="agenda-timer__no-allocation" data-testid="agenda-item-timer-no-allocation">
			{{ t('decidesk', 'No time allocated — elapsed time is tracked for analytics.') }}
			<span class="agenda-timer__elapsed">{{ elapsedText }}</span>
		</p>

		<div v-if="isChair" class="agenda-timer__controls">
			<NcButton
				v-if="!started"
				variant="primary"
				data-testid="agenda-item-timer-start"
				:aria-label="t('decidesk', 'Start timer')"
				@click="start">
				{{ t('decidesk', 'Start') }}
			</NcButton>
			<template v-else-if="!finished">
				<NcButton
					v-if="!isPaused"
					data-testid="agenda-item-timer-pause"
					:aria-label="t('decidesk', 'Pause timer')"
					@click="pause">
					{{ t('decidesk', 'Pause') }}
				</NcButton>
				<NcButton
					v-else
					data-testid="agenda-item-timer-resume"
					:aria-label="t('decidesk', 'Resume timer')"
					@click="resume">
					{{ t('decidesk', 'Resume') }}
				</NcButton>
				<NcButton
					v-if="hasAllocation"
					data-testid="agenda-item-timer-extend5"
					:aria-label="t('decidesk', 'Extend 5 minutes')"
					@click="extend(5)">
					{{ t('decidesk', 'Extend 5 min') }}
				</NcButton>
				<NcButton
					v-if="hasAllocation"
					data-testid="agenda-item-timer-extend10"
					:aria-label="t('decidesk', 'Extend 10 minutes')"
					@click="extend(10)">
					{{ t('decidesk', 'Extend 10 min') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:loading="closing"
					data-testid="agenda-item-timer-close"
					:aria-label="t('decidesk', 'Close agenda item')"
					@click="close">
					{{ t('decidesk', 'Close item') }}
				</NcButton>
			</template>
			<span v-else class="agenda-timer__closed" data-testid="agenda-item-timer-closed">
				{{ t('decidesk', 'Item closed ({minutes} min)', { minutes: closedMinutes }) }}
			</span>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import {
	createTimer,
	startTimer,
	pauseTimer,
	resumeTimer,
	extendTimer,
	finishTimer,
	elapsedSeconds,
	pausedSeconds,
	remainingSeconds,
	isOverTime,
	formatClock,
} from '../../utils/meetingTimer.js'

/**
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export default {
	name: 'AgendaItemTimer',

	components: { NcButton },

	props: {
		item: { type: Object, required: true },
		isChair: { type: Boolean, default: false },
		objectStore: { type: Object, required: true },
	},

	data() {
		return {
			timer: createTimer(this.allocatedSeconds(this.item)),
			now: Date.now(),
			intervalId: null,
			closing: false,
		}
	},

	computed: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		hasAllocation() {
			return this.timer.allocatedSeconds !== null
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		started() {
			return this.timer.startedAt !== null
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		isPaused() {
			return this.timer.pausedAt !== null
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		finished() {
			return this.timer.finished
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		overTime() {
			return isOverTime(this.timer, this.now)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		clockText() {
			const remaining = remainingSeconds(this.timer, this.now)
			return formatClock(remaining === null ? elapsedSeconds(this.timer, this.now) : remaining)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		elapsedText() {
			return formatClock(elapsedSeconds(this.timer, this.now))
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		closedMinutes() {
			return Math.round(elapsedSeconds(this.timer, this.now) / 60)
		},
	},

	watch: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		'item.id'() {
			// New active item: reset the timer to that item's allocation.
			this.timer = createTimer(this.allocatedSeconds(this.item))
		},
	},

	/** @spec exclude lifecycle hook; starts the 1s render tick only */
	mounted() {
		this.intervalId = setInterval(() => { this.now = Date.now() }, 1000)
	},

	/** @spec exclude lifecycle teardown; clears the render interval */
	beforeUnmount() {
		if (this.intervalId) clearInterval(this.intervalId)
	},

	methods: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		allocatedSeconds(item) {
			const minutes = Number(item?.estimatedDuration)
			return Number.isFinite(minutes) && minutes > 0 ? minutes * 60 : null
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		start() {
			this.now = Date.now()
			this.timer = startTimer(this.timer, this.now)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		pause() {
			this.now = Date.now()
			this.timer = pauseTimer(this.timer, this.now)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		resume() {
			this.now = Date.now()
			this.timer = resumeTimer(this.timer, this.now)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		extend(minutes) {
			this.timer = extendTimer(this.timer, minutes * 60)
		},
		/**
		 * Close the item: freeze the timer, persist actualDuration and the
		 * separately-recorded pausedDuration (minutes) to the agenda item, and
		 * emit `closed` so the parent can advance.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		async close() {
			this.now = Date.now()
			this.timer = finishTimer(this.timer, this.now)
			const actualDuration = Math.round(elapsedSeconds(this.timer, this.now) / 60)
			const pausedDuration = Math.round(pausedSeconds(this.timer, this.now) / 60)
			this.closing = true
			try {
				await this.objectStore.saveObject('agenda-item', {
					...this.item,
					actualDuration,
					pausedDuration,
				})
				this.$emit('closed', { itemId: this.item.id, actualDuration, pausedDuration })
			} catch (e) {
				console.error('Failed to persist agenda item duration:', e)
			} finally {
				this.closing = false
			}
		},
	},
}
</script>

<style scoped>
.agenda-timer {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-block: var(--default-grid-baseline);
}

.agenda-timer__clock {
	font-size: 2rem;
	font-variant-numeric: tabular-nums;
	font-weight: 700;
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.agenda-timer__clock--over {
	color: var(--color-error);
	animation: agenda-timer-pulse 1s ease-in-out infinite;
}

.agenda-timer__clock--paused {
	color: var(--color-text-maxcontrast);
}

@keyframes agenda-timer-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.4; }
}

/*
 * The over-time clock pulses indefinitely, which is precisely the pattern
 * vestibular-disorder and migraine users need to be able to switch off.
 *
 * Removing the animation loses NO information here: "over time" is already
 * carried by --color-error on the clock and by the visible
 * .agenda-timer__over-tag text beside it, so the state stays perceivable
 * without motion (WCAG 1.4.1 — colour and motion are not the only channels).
 * The clock is pinned to full opacity rather than left mid-keyframe, because
 * an interrupted animation can otherwise settle at opacity 0.4 and read as
 * disabled.
 */
@media (prefers-reduced-motion: reduce) {
	.agenda-timer__clock--over {
		animation: none;
		opacity: 1;
	}
}

.agenda-timer__paused-tag,
.agenda-timer__over-tag {
	font-size: 0.875rem;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: var(--border-radius);
}

.agenda-timer__paused-tag {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.agenda-timer__over-tag {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.agenda-timer__no-allocation {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.agenda-timer__elapsed {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	margin-inline-start: 8px;
}

.agenda-timer__controls {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	align-items: center;
}

.agenda-timer__closed {
	color: var(--color-text-maxcontrast);
}
</style>
