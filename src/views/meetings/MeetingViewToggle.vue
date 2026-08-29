<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 MeetingViewToggle — the Table / Calendar segmented control on the meeting
 index, injected through the manifest page's `actionsComponent` slot.

 WHY THIS IS A LOCAL COMPONENT AND NOT A SHARED VIEW MODE

 CnIndexPage offers `table`, `cards`, `list` and `map`; its `viewMode` prop
 validator rejects anything else, so `calendar` cannot be passed to it. Adding
 the mode to @conduction/nextcloud-vue would be the better long-term home, but
 the shared library is owned by another change this wave, and a leaf app must
 never fork it. So the toggle routes between the manifest's own `index` page
 (the table, untouched) and a decidiq-local calendar page — no shared-library
 edit, and no re-implementation of CnPageRenderer's index prop mapping, which
 wrapping CnIndexPage here would have required.

 @spec openspec/changes/configurable-types-domain-model/design.md
-->
<template>
	<div
		class="meeting-view-toggle"
		role="group"
		:aria-label="t('decidiq', 'Meeting view')">
		<NcButton
			:variant="isCalendar ? 'tertiary' : 'secondary'"
			:aria-pressed="String(!isCalendar)"
			data-testid="meeting-view-table"
			@click="show('table')">
			<template #icon>
				<TableIcon :size="20" />
			</template>
			{{ t('decidiq', 'Table') }}
		</NcButton>
		<NcButton
			:variant="isCalendar ? 'secondary' : 'tertiary'"
			:aria-pressed="String(isCalendar)"
			data-testid="meeting-view-calendar"
			@click="show('calendar')">
			<template #icon>
				<CalendarMonthIcon :size="20" />
			</template>
			{{ t('decidiq', 'Calendar') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CalendarMonthIcon from 'vue-material-design-icons/CalendarMonth.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'

export default {
	name: 'MeetingViewToggle',

	components: { NcButton, CalendarMonthIcon, TableIcon },

	computed: {
		/**
		 * Whether the calendar page is the one currently shown.
		 *
		 * @return {boolean} True on the calendar route.
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		isCalendar() {
			return this.$route && this.$route.name === 'MeetingsCalendar'
		},
	},

	methods: {
		/**
		 * Switch between the table and calendar surfaces.
		 *
		 * Navigation is guarded against pushing the route the router is already
		 * on, which vue-router rejects with a NavigationDuplicated error that
		 * would surface in the console on a double-click.
		 *
		 * @param {string} which Either 'table' or 'calendar'.
		 * @return {void}
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		show(which) {
			const target = which === 'calendar' ? 'MeetingsCalendar' : 'Meetings'
			if (this.$route && this.$route.name === target) {
				return
			}
			this.$router.push({ name: target })
		},
	},
}
</script>

<style scoped>
.meeting-view-toggle {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}
</style>
