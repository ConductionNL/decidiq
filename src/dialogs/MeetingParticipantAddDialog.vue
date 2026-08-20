<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: pick a participant to link to a Meeting.

 Extracted from MeetingParticipantsTab per the modal-isolation rule
 (ADR-004): the dialog renders the candidate choices; the parent owns the
 candidate fetch and performs the actual relation write on @select.

 @spec openspec/specs/relation-tab-ui/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Add participant')"
		data-testid="meeting-participant-add-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>{{ t('decidesk', 'Pick a participant to link to this meeting.') }}</p>
			<div v-if="loading" class="decidesk-tab__loading">
				{{ t('decidesk', 'Loading participants…') }}
			</div>
			<ul v-else-if="candidates.length" class="decidesk-tab__list">
				<li v-for="cand in candidates" :key="cand.id">
					<NcButton @click="$emit('select', cand)">
						{{ candidateLabel(cand) }}
					</NcButton>
				</li>
			</ul>
			<p v-else class="decidesk-tab__empty">
				{{ t('decidesk', 'No more participants available to link.') }}
			</p>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'MeetingParticipantAddDialog',

	components: { NcButton, NcDialog },

	props: {
		/** Participants not yet linked to the meeting */
		candidates: { type: Array, default: () => [] },
		/** Whether the candidate list is still being fetched */
		loading: { type: Boolean, default: false },
	},

	emits: ['select', 'close'],

	methods: {
		/**
		 * @param p
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		candidateLabel(p) {
			return p.displayName || p.name || p.id
		},
	},
}
</script>

<style scoped>
.decidesk-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decidesk-tab__empty,
.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
