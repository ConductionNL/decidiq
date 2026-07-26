<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting cost panel (meeting-efficiency / cost calculator).

 Toggleable (hidden by default) live running cost = elapsed hours x present
 attendees x the governance body's hourlyRate, ticking every second. Elapsed
 is read from the meeting's server-stamped openedAt; the count is the present
 participants (falling back to all linked participants when none are flagged
 present). Shows a hint instead of a figure when the body has no rate
 configured. This panel is display-only — the persisted meetingCost is stamped
 server-side on close so analytics can trust it. The cost formula lives in
 src/utils/meetingCost.js (pure, vitest-covered).

 @spec openspec/specs/meeting-efficiency/spec.md
-->
<template>
	<section class="meeting-cost" data-testid="meeting-cost-panel" :aria-label="t('decidesk', 'Meeting cost')">
		<div class="meeting-cost__header">
			<h4 class="meeting-cost__title">
				{{ t('decidesk', 'Meeting cost') }}
			</h4>
			<NcButton
				size="small"
				data-testid="meeting-cost-toggle"
				:aria-pressed="visible"
				:aria-label="visible ? t('decidesk', 'Hide meeting cost') : t('decidesk', 'Show meeting cost')"
				@click="visible = !visible">
				{{ visible ? t('decidesk', 'Hide') : t('decidesk', 'Show') }}
			</NcButton>
		</div>

		<template v-if="visible">
			<p v-if="!hasRate" class="meeting-cost__hint" data-testid="meeting-cost-no-rate">
				{{ t('decidesk', 'No hourly rate configured on this governance body — set one to see the running cost.') }}
			</p>
			<template v-else>
				<p class="meeting-cost__figure" data-testid="meeting-cost-figure" aria-live="polite">
					{{ formattedCost }}
				</p>
				<p class="meeting-cost__detail">
					{{ t('decidesk', '{count} attendees × {rate}/h', { count: attendeeCount, rate: formattedRate }) }}
				</p>
			</template>
		</template>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { computeMeetingCost, formatEur } from '../../utils/meetingCost.js'

/**
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export default {
	name: 'MeetingCostPanel',

	components: { NcButton },

	props: {
		meeting: { type: Object, required: true },
		participants: { type: Array, default: () => [] },
		hourlyRate: { type: Number, default: 0 },
	},

	data() {
		return {
			visible: false,
			now: Date.now(),
			intervalId: null,
		}
	},

	computed: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		hasRate() {
			return Number.isFinite(this.hourlyRate) && this.hourlyRate > 0
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		attendeeCount() {
			const present = this.participants.filter(p => p.present === true)
			return present.length > 0 ? present.length : this.participants.length
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		elapsedSeconds() {
			const openedAt = this.meeting?.openedAt
			if (!openedAt) return 0
			const startMs = Date.parse(openedAt)
			if (!Number.isFinite(startMs)) return 0
			return Math.max(0, Math.floor((this.now - startMs) / 1000))
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		formattedCost() {
			return formatEur(computeMeetingCost(this.elapsedSeconds, this.attendeeCount, this.hourlyRate))
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		formattedRate() {
			return formatEur(this.hourlyRate)
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
}
</script>

<style scoped>
.meeting-cost {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.meeting-cost__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.meeting-cost__title {
	margin: 0;
}

.meeting-cost__figure {
	font-size: 2rem;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	margin: var(--default-grid-baseline) 0 0;
}

.meeting-cost__detail,
.meeting-cost__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
