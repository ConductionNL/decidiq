<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 InForceOnDateFilter — the "geldig op" date choice above the delegations
 index (delegatie-mandaatregister, REQ-DMR-004). Choosing a day writes the
 four-key validity-window query into the address; the index page merges its
 route query into the list fetch, so the list answers "which delegations are
 in force on this day" in one OpenRegister query. Clearing the day takes the
 four keys off again and keeps every other filter.
-->
<template>
	<div class="in-force-on-date" data-testid="in-force-on-date">
		<NcDateTimePickerNative
			id="in-force-on-date"
			v-model="pickedDate"
			type="date"
			data-testid="in-force-on-date-input"
			:label="t('decidiq', 'In force on')" />
	</div>
</template>

<script>
import { NcDateTimePickerNative } from '@nextcloud/vue'
import { dayFromQuery, isoDay, withInForceDay } from '../../utils/inForceOnDate.js'

export default {
	name: 'InForceOnDateFilter',

	components: { NcDateTimePickerNative },

	computed: {
		pickedDate: {
			/**
			 * The day the address asks about, as a Date for the picker.
			 *
			 * @return {Date|null} The day, or null when none is chosen.
			 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
			 */
			get() {
				const day = dayFromQuery(this.$route && this.$route.query)
				if (day === '') {
					return null
				}
				const [year, month, date] = day.split('-').map(Number)
				return new Date(year, month - 1, date)
			},

			/**
			 * A picked day goes into the address.
			 *
			 * @param {Date|null} value The picked date.
			 * @return {void}
			 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
			 */
			set(value) {
				this.onPick(value)
			},
		},
	},

	methods: {
		/**
		 * Put the chosen day (or none) into the address.
		 *
		 * @param {Date|null} value The picked date.
		 * @return {void}
		 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
		 */
		onPick(value) {
			const query = (this.$route && this.$route.query) || {}
			const next = withInForceDay(query, isoDay(value))
			if (JSON.stringify(next) === JSON.stringify(query)) {
				return
			}
			this.$router.replace({ query: next }).catch(() => {})
		},
	},
}
</script>

<style scoped>
.in-force-on-date {
	padding: calc(2 * var(--default-grid-baseline, 4px));
	max-width: 280px;
}
</style>
