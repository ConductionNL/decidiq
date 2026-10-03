<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Seats panel of the live meeting (bodies-substitute-mandate-swap, design D4).
 Lists the seats in seat order with name and party, from the server's seats
 read rather than the client-side participant filter. A seat a substitute
 holds names who they substitute for. For the chair and the secretary each
 member has "Swap with substitute", and a substituted seat has "End
 substitution"; the server refuses both for anyone else and during a vote.

 @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
-->
<template>
	<section
		class="seats-panel"
		data-testid="seats-panel"
		:aria-label="t('decidiq', 'Seats')">
		<h4 class="seats-panel__title">
			{{ t('decidiq', 'Seats') }}
		</h4>
		<p v-if="error" class="seats-panel__error" role="alert">
			{{ error }}
		</p>
		<p v-if="!loading && rows.length === 0" class="seats-panel__empty">
			{{ t('decidiq', 'No seats to show for this meeting.') }}
		</p>
		<ul class="seats-panel__list" role="list">
			<li
				v-for="row in rows"
				:key="row.substitution ? row.substitution.id : row.id"
				class="seats-panel__seat"
				data-testid="seats-panel-seat"
				role="listitem">
				<span class="seats-panel__number">{{ row.seat ?? '-' }}</span>
				<span class="seats-panel__name">{{ row.name }}</span>
				<span v-if="row.party" class="seats-panel__party">{{ row.party }}</span>
				<span
					v-if="row.substitution"
					class="seats-panel__substitute"
					data-testid="seats-panel-substitute-for">
					{{ t('decidiq', 'substitute for {name}', { name: row.substituteFor }) }}
				</span>
				<template v-if="canSubstitute">
					<NcButton
						v-if="row.canSwap"
						size="small"
						data-testid="seats-panel-swap"
						:aria-label="t('decidiq', 'Swap {name} with a substitute', { name: row.name })"
						@click="swapping = row">
						{{ t('decidiq', 'Swap with substitute') }}
					</NcButton>
					<NcButton
						v-if="row.substitution"
						size="small"
						data-testid="seats-panel-end"
						:disabled="ending"
						:aria-label="t('decidiq', 'End the substitution of {name}', { name: row.substituteFor })"
						@click="end(row)">
						{{ t('decidiq', 'End substitution') }}
					</NcButton>
				</template>
			</li>
		</ul>

		<MandateSwapModal
			v-if="swapping"
			:meetingId="meetingId"
			:row="swapping"
			:options="options"
			@close="swapping = null"
			@saved="onSaved" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import MandateSwapModal from '../../modals/MandateSwapModal.vue'
import { seatRows, seatsRequest, substituteOptions } from '../../utils/meetingSeats.js'

export default {
	name: 'SeatsPanel',

	components: { NcButton, MandateSwapModal },

	props: {
		/** The meeting. */
		meetingId: { type: String, required: true },
	},

	data() {
		return {
			seats: { participants: [], substitutions: [], canSubstitute: false },
			loading: true,
			ending: false,
			swapping: null,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting */
		rows() {
			return seatRows(this.seats)
		},
		/** @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan */
		options() {
			return substituteOptions(this.seats)
		},
		/** @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting */
		canSubstitute() {
			return this.seats.canSubstitute === true
		},
	},

	/** @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the seats from the server.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
		 */
		async load() {
			this.loading = true
			try {
				this.seats = await seatsRequest('GET', this.meetingId, 'seats')
				this.error = ''
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The seats could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * After a swap, close the modal and read the seats again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
		 */
		async onSaved() {
			this.swapping = null
			await this.load()
		},

		/**
		 * End a substitution; the seat goes back to the member.
		 *
		 * @param {object} row The seat row with its substitution.
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
		 */
		async end(row) {
			this.ending = true
			try {
				await seatsRequest('POST', this.meetingId, `substitutions/${encodeURIComponent(row.substitution.id)}/end`)
				await this.load()
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The substitution could not be ended.')
			} finally {
				this.ending = false
			}
		},
	},
}
</script>

<style scoped>
.seats-panel__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: 0;
}

.seats-panel__seat {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.seats-panel__number {
	min-width: calc(var(--default-grid-baseline) * 6);
	font-weight: bold;
}

.seats-panel__party,
.seats-panel__substitute {
	color: var(--color-text-maxcontrast);
}

.seats-panel__error {
	color: var(--color-error-text);
}
</style>
