<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: pick recurring agenda items to copy onto the current agenda.

 Extracted from AgendaBuilder per the modal-isolation rule (ADR-004):
 the dialog owns only the selection state; the parent performs the
 actual object writes on @add.

 @spec openspec/specs/agenda-management/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Add recurring items')"
		data-testid="recurring-items-dialog"
		@closing="$emit('close')">
		<template #default>
			<ul
				v-if="recurringItems.length > 0"
				class="recurring-dialog__list"
				role="list">
				<li
					v-for="rItem in recurringItems"
					:key="rItem.id"
					class="recurring-dialog__item"
					role="listitem">
					<!-- nc-vue v9: NcCheckboxRadioSwitch's prop is `modelValue`, not
					     `checked`. The Vue-2-era `:checked` / `@update:checked` pair is
					     undeclared, so BOTH fall through `inheritAttrs: false` onto the
					     raw <input>: `checked` sets the native attribute (the box looks
					     right) while the component's own modelValue stays false, and
					     `onUpdate:checked` is registered for a DOM event that is never
					     fired — so toggling did nothing at all here. Same defect as the
					     one fixed in userSettings/NotificationPreferencesSection.vue. -->
					<NcCheckboxRadioSwitch
						:modelValue="selected.includes(rItem.id)"
						@update:modelValue="toggle(rItem.id)">
						{{ rItem.title }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<p v-else>
				{{ t('decidiq', 'No recurring agenda items found.') }}
			</p>
		</template>
		<template #actions>
			<NcButton
				:disabled="selected.length === 0"
				@click="$emit('add', selected.slice())">
				{{ t('decidiq', 'Add selected') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'

export default {
	name: 'RecurringItemsDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },

	props: {
		/** Recurring agenda items available for selection */
		recurringItems: { type: Array, default: () => [] },
	},

	emits: ['add', 'close'],

	data() {
		return {
			selected: [],
		}
	},

	methods: {
		/**
		 * @param id
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		toggle(id) {
			const idx = this.selected.indexOf(id)
			if (idx === -1) {
				this.selected.push(id)
			} else {
				this.selected.splice(idx, 1)
			}
		},
	},
}
</script>

<style scoped>
.recurring-dialog__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.recurring-dialog__item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.recurring-dialog__item:last-child {
	border-bottom: none;
}
</style>
