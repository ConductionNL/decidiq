<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Detail widget: the phase of an agenda item as a stage line, as DcAgendapunt
 draws it (agenda-item-phase-is-declared, REQ-LIV-011). Forming a picture,
 forming an opinion, deciding, with the item's phase marked. An informational
 item has no phase and shows a short note instead. Read-only: the chair moves
 the phase on the live meeting screen.

 @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase
-->
<template>
	<div class="decidiq-tab decidiq-tab--phase" data-testid="agenda-item-phase-tab">
		<NcLoadingIcon v-if="loading" :size="32" />

		<CnNoteCard v-else-if="error" type="error">
			{{ error }}
		</CnNoteCard>

		<template v-else-if="item">
			<CnTimelineStages
				v-if="showsPhase"
				data-testid="agenda-item-phase-stages"
				:stages="stages"
				:current="current"
				:aria-label="t('decidiq', 'Phase of this agenda item')" />
			<p v-else class="decidiq-tab__empty" data-testid="agenda-item-no-phase">
				{{ t('decidiq', 'An informational item has no phase.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { CnNoteCard, CnTimelineStages } from '@conduction/nextcloud-vue'
import { NcLoadingIcon } from '@nextcloud/vue'
import {
	AGENDA_PHASE_STAGES,
	hasPhase,
	phaseStageIndex,
} from '../../utils/agendaPhase.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'AgendaItemPhaseTab',
	components: { CnNoteCard, CnTimelineStages, NcLoadingIcon },

	/** @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase */
	mixins: [objectIdLoader],

	data() {
		return {
			loading: false,
			error: '',
			item: null,
		}
	},

	computed: {
		/** @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase */
		showsPhase() {
			return hasPhase(this.item)
		},

		/** @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase */
		stages() {
			return AGENDA_PHASE_STAGES.map((stage) => ({
				...stage,
				label: this.t('decidiq', stage.label),
			}))
		},

		/** @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase */
		current() {
			return phaseStageIndex(this.item)
		},
	},

	methods: {
		/** @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('agenda-item')
				this.item = (await store.fetchObject('agenda-item', this.objectId)) || null
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Could not load the phase')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
