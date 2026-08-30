<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: assign or remove the spokesperson for an agenda item.

 Extracted from AgendaBuilder per the modal-isolation rule (ADR-004):
 the dialog renders the participant choices; the parent performs the
 actual relation write on @assign / @remove.

 @spec openspec/specs/agenda-management/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Assign spokesperson')"
		data-testid="spokesperson-dialog"
		@closing="$emit('close')">
		<template #default>
			<ul
				v-if="participants.length > 0"
				class="spokesperson-dialog__list"
				role="list">
				<li
					v-for="p in participants"
					:key="p.id"
					class="spokesperson-dialog__item"
					role="listitem">
					<NcButton @click="$emit('assign', p)">
						{{ p.displayName }}
					</NcButton>
				</li>
			</ul>
			<p v-else>
				{{ t('decidiq', 'No participants found.') }}
			</p>
			<NcButton
				v-if="hasSpokesperson"
				variant="error"
				@click="$emit('remove')">
				{{ t('decidiq', 'Remove spokesperson') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'SpokespersonDialog',

	components: { NcButton, NcDialog },

	props: {
		/** Participants available for spokesperson assignment */
		participants: { type: Array, default: () => [] },
		/** Whether the item currently has a spokesperson (shows the remove action) */
		hasSpokesperson: { type: Boolean, default: false },
	},

	emits: ['assign', 'remove', 'close'],
}
</script>

<style scoped>
.spokesperson-dialog__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.spokesperson-dialog__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.spokesperson-dialog__item:last-child {
	border-bottom: none;
}
</style>
