<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Actions on one long-term agenda item: reschedule (with a mandatory reason
 and an appended shift-history entry), mark as realised (linking the agenda
 item or decision that realised it) and mark as lapsed (with a mandatory
 reason). A realised or lapsed item is final, so the actions disappear.
 Each action saves the whole item once; OpenRegister's transition map has the
 last word and its refusal is shown as the message.

 @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
-->
<template>
	<div class="decidiq-tab" data-testid="planned-agenda-actions-tab">
		<p v-if="!item" class="decidiq-planned-actions__muted">
			{{ t('decidiq', 'Loading…') }}
		</p>
		<template v-else>
			<p data-testid="planned-agenda-actions-state">
				{{ stateText }}
			</p>
			<p v-if="ownerWarning" class="decidiq-planned-actions__warning" role="status">
				{{ t('decidiq', 'A portfolio holder as owner needs the person who holds the portfolio.') }}
			</p>
			<div v-if="!settled" class="decidiq-planned-actions__buttons">
				<NcButton data-testid="planned-agenda-action-reschedule" :disabled="busy" @click="open = 'reschedule'">
					{{ t('decidiq', 'Reschedule…') }}
				</NcButton>
				<NcButton data-testid="planned-agenda-action-realise" :disabled="busy" @click="openRealise">
					{{ t('decidiq', 'Mark as realised…') }}
				</NcButton>
				<NcButton variant="tertiary" data-testid="planned-agenda-action-lapse" :disabled="busy" @click="open = 'lapse'">
					{{ t('decidiq', 'Mark as lapsed…') }}
				</NcButton>
			</div>
			<p v-if="error" class="decidiq-planned-actions__error" role="alert">
				{{ error }}
			</p>
		</template>

		<PlannedAgendaRescheduleDialog
			v-if="open === 'reschedule'"
			:item="item"
			@close="open = ''"
			@confirm="reschedule" />
		<PlannedAgendaRealiseDialog
			v-if="open === 'realise'"
			:agendaItems="suggestions.agendaItems"
			:decisions="suggestions.decisions"
			:loading="suggestions.loading"
			@close="open = ''"
			@confirm="realise" />
		<PlannedAgendaLapseDialog
			v-if="open === 'lapse'"
			@close="open = ''"
			@confirm="lapse" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton } from '@nextcloud/vue'
import PlannedAgendaLapseDialog from '../../dialogs/PlannedAgendaLapseDialog.vue'
import PlannedAgendaRealiseDialog from '../../dialogs/PlannedAgendaRealiseDialog.vue'
import PlannedAgendaRescheduleDialog from '../../dialogs/PlannedAgendaRescheduleDialog.vue'
import {
	buildLapse,
	buildRealise,
	buildReschedule,
	isTerminal,
	ownerProblem,
	PLANNED_AGENDA_SCHEMA,
	realisationCandidates,
	suggestRealisations,
} from '../../utils/plannedAgenda.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'PlannedAgendaActionsTab',
	components: {
		NcButton,
		PlannedAgendaLapseDialog,
		PlannedAgendaRealiseDialog,
		PlannedAgendaRescheduleDialog,
	},

	mixins: [objectIdLoader],

	data() {
		return {
			item: null,
			open: '',
			busy: false,
			error: '',
			suggestions: { agendaItems: [], decisions: [], loading: false },
		}
	},

	computed: {
		settled() {
			return isTerminal(this.item)
		},

		ownerWarning() {
			return ownerProblem(this.item) !== null
		},

		stateText() {
			const lifecycle = this.item?.lifecycle
			if (lifecycle === 'realised') return this.t('decidiq', 'This item has been realised.')
			if (lifecycle === 'lapsed') {
				return this.t('decidiq', 'This item lapsed: {reason}', { reason: this.item.reasonExpired || '—' })
			}
			return this.t('decidiq', 'Planned for {period}.', { period: this.item?.plannedPeriod || '—' })
		},
	},

	methods: {
		/**
		 * Read the item.
		 *
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
		 */
		async load() {
			this.item = null
			this.error = ''
			if (!this.objectId) return
			try {
				this.item = await ensureRelationType(PLANNED_AGENDA_SCHEMA).fetchObject(
					PLANNED_AGENDA_SCHEMA,
					String(this.objectId),
				)
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The item could not be loaded.')
			}
		},

		/**
		 * Open the realise dialog and look up the body's agenda items and
		 * decisions to suggest. Read-only: nothing is created.
		 *
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
		 */
		async openRealise() {
			this.open = 'realise'
			this.suggestions = { agendaItems: [], decisions: [], loading: true }
			const { agendaItems, decisions } = await realisationCandidates(
				(type, params) => ensureRelationType(type).fetchCollection(type, params),
				this.item?.governanceBody,
			)
			this.suggestions = {
				agendaItems: suggestRealisations(this.item, agendaItems),
				decisions: suggestRealisations(this.item, decisions),
				loading: false,
			}
		},

		/**
		 * Save the item built by one of the rules, once.
		 *
		 * @param {Function} build Builds the item to save from the current one.
		 */
		async save(build) {
			this.error = ''
			this.busy = true
			try {
				const next = build(this.item)
				const saved = await ensureRelationType(PLANNED_AGENDA_SCHEMA).saveObject(PLANNED_AGENDA_SCHEMA, next)
				this.item = saved || next
				this.open = ''
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The item was not saved.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {{toPeriod: string, reason: string}} shift The new period and reason.
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-004-every-reschedule-records-a-mandatory-reason-and-preserves-full-shift-history
		 */
		reschedule({ toPeriod, reason }) {
			return this.save((item) => buildReschedule(item, {
				toPeriod,
				reason,
				user: getCurrentUser()?.uid || '',
				now: Date.now(),
			}))
		},

		/**
		 * @param {{agendaItem: string, decision: string}} links The confirmed links.
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
		 */
		realise(links) {
			return this.save((item) => buildRealise(item, links))
		},

		/**
		 * @param {string} reason Why it lapses.
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-003-lifecycle-is-declarative-with-shift-and-settle-states
		 */
		lapse(reason) {
			return this.save((item) => buildLapse(item, reason))
		},
	},
}
</script>

<style scoped>
.decidiq-planned-actions__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.decidiq-planned-actions__muted {
	color: var(--color-text-maxcontrast);
}

.decidiq-planned-actions__warning {
	color: var(--color-warning-text, var(--color-warning));
}

.decidiq-planned-actions__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
