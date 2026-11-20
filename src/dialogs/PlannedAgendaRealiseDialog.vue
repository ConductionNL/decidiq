<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Realise dialog for a long-term agenda item. It suggests the agenda items
 and decisions of the same governance body, best subject matches first; the
 user confirms which one realised the item. The flow only links: it never
 creates or changes a meeting, agenda item or decision.

 @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Mark as realised')"
		data-testid="planned-agenda-realise-dialog"
		@closing="$emit('close')">
		<template #default>
			<p v-if="loading">
				{{ t('decidiq', 'Looking for matching agenda items…') }}
			</p>
			<template v-else>
				<fieldset class="decidiq-realise__group">
					<legend>{{ t('decidiq', 'Agenda item that realised it') }}</legend>
					<p v-if="agendaItems.length === 0" class="decidiq-realise__empty">
						{{ t('decidiq', 'No agenda items of this governance body were found.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-for="candidate in agendaItems"
						:key="candidate.id"
						v-model="agendaItem"
						:value="String(candidate.id)"
						type="radio"
						name="planned-agenda-realise-agenda-item"
						data-testid="planned-agenda-realise-agenda-item">
						{{ candidate.title || candidate.name || candidate.id }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<fieldset class="decidiq-realise__group">
					<legend>{{ t('decidiq', 'Decision that realised it') }}</legend>
					<p v-if="decisions.length === 0" class="decidiq-realise__empty">
						{{ t('decidiq', 'No decisions of this governance body were found.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-for="candidate in decisions"
						:key="candidate.id"
						v-model="decision"
						:value="String(candidate.id)"
						type="radio"
						name="planned-agenda-realise-decision"
						data-testid="planned-agenda-realise-decision">
						{{ candidate.title || candidate.name || candidate.id }}
					</NcCheckboxRadioSwitch>
				</fieldset>
			</template>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="planned-agenda-realise-confirm"
				:disabled="!agendaItem && !decision"
				@click="$emit('confirm', { agendaItem, decision })">
				{{ t('decidiq', 'Mark as realised') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'

export default {
	name: 'PlannedAgendaRealiseDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },
	props: {
		agendaItems: { type: Array, default: () => [] },
		decisions: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},

	emits: ['close', 'confirm'],
	data() {
		return { agendaItem: '', decision: '' }
	},
}
</script>

<style scoped>
.decidiq-realise__group {
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 3);
	border: none;
	padding: 0;
}

.decidiq-realise__group legend {
	font-weight: bold;
}

.decidiq-realise__empty {
	color: var(--color-text-maxcontrast);
}
</style>
