<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: pick a participant to request a signature from on a Minutes record.

 Extracted from MinutesSignersTab per the modal-isolation rule (ADR-004):
 the dialog renders the candidate choices; the parent owns the candidate
 fetch and performs the actual signer write on @select.

 @spec openspec/specs/relation-tab-ui/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Add signer')"
		data-testid="minutes-signer-add-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidiq', 'Pick a participant to request a signature from.') }}
			</p>
			<div v-if="loading" class="decidiq-tab__loading">
				{{ t('decidiq', 'Loading participants…') }}
			</div>
			<ul v-else-if="candidates.length" class="decidiq-tab__list">
				<li v-for="cand in candidates" :key="cand.id">
					<NcButton @click="$emit('select', cand)">
						{{ candidateLabel(cand) }}
					</NcButton>
				</li>
			</ul>
			<p v-else class="decidiq-tab__empty">
				{{ t('decidiq', 'All participants already added as signers.') }}
			</p>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'MinutesSignerAddDialog',

	components: { NcButton, NcDialog },

	props: {
		/** Participants not yet linked as signers */
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
.decidiq-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decidiq-tab__empty,
.decidiq-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
