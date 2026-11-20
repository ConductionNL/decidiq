<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  The urgency banner on the decision detail: "urgent — awaiting ratification"
  naming the ratifying body and where ratification is scheduled (or that no
  meeting exists yet), shown next to — never instead of — the lifecycle badge.
  Icon and text, CSS variables only. It also offers the two guarded actions:
  declare urgent, and open the expedited written round.

  @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi
-->
<template>
	<div class="urgency-banner" data-testid="urgency-banner">
		<div
			v-if="state.urgent"
			class="urgency-banner__notice"
			:class="{ 'urgency-banner__notice--awaiting': state.awaiting }"
			role="status">
			<AlertDecagramOutline v-if="state.awaiting" :size="20" class="urgency-banner__icon" />
			<CheckDecagramOutline v-else :size="20" class="urgency-banner__icon" />
			<div>
				<strong v-if="state.awaiting">
					{{ bodyName ? t('decidiq', 'Urgent — awaiting ratification by {body}', { body: bodyName }) : t('decidiq', 'Urgent — awaiting ratification') }}
				</strong>
				<strong v-else>{{ t('decidiq', 'Urgent decision — ratified') }}</strong>
				<p v-if="decision.urgencyReason" class="urgency-banner__reason">
					{{ decision.urgencyReason }}
				</p>
				<p v-if="state.placementPending" class="urgency-banner__warning" data-testid="urgency-placement-pending">
					{{ t('decidiq', 'No regular meeting of the ratifying body is scheduled yet. The ratification is put on the agenda as soon as one is created.') }}
				</p>
				<p v-else-if="state.awaiting && meetingLabel" data-testid="urgency-placement">
					{{ t('decidiq', 'Ratification is on the agenda of {meeting}.', { meeting: meetingLabel }) }}
				</p>
				<p v-if="decision.reportedToRatifyingBodyAt" data-testid="urgency-reported">
					{{ t('decidiq', 'Reported to the ratifying body on {date}', { date: new Date(decision.reportedToRatifyingBodyAt).toLocaleDateString() }) }}
				</p>
			</div>
		</div>
		<div class="urgency-banner__actions">
			<NcButton
				v-if="!state.urgent && decision.id"
				data-testid="urgency-declare"
				@click="openDialog('declare')">
				{{ t('decidiq', 'Declare urgent') }}
			</NcButton>
			<NcButton
				v-if="state.awaiting"
				data-testid="urgency-expedited-round"
				@click="openDialog('round')">
				{{ t('decidiq', 'Open expedited written round') }}
			</NcButton>
			<NcButton
				v-if="reportable"
				data-testid="urgency-report"
				:disabled="saving"
				@click="report">
				{{ t('decidiq', 'Mark as reported to the ratifying body') }}
			</NcButton>
		</div>
		<CnNoteCard v-if="message" type="success">
			{{ message }}
		</CnNoteCard>
		<DeclareUrgencyDialog
			v-if="dialog === 'declare'"
			:saving="saving"
			:error="error"
			:bodies="bodies"
			:defaultBody="defaultBody"
			@submit="submit('urgency', $event)"
			@close="closeDialog" />
		<ExpeditedRoundDialog
			v-if="dialog === 'round'"
			:saving="saving"
			:error="error"
			@submit="submit('expedited-round', $event)"
			@close="closeDialog" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import AlertDecagramOutline from 'vue-material-design-icons/AlertDecagramOutline.vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import DeclareUrgencyDialog from '../../dialogs/DeclareUrgencyDialog.vue'
import ExpeditedRoundDialog from '../../dialogs/ExpeditedRoundDialog.vue'
import { canReport, postUrgentAction, urgencyState } from '../../services/urgency.js'
import { ensureRelationType } from '../tabs/useRelationStore.js'

/**
 * A relation value (uuid or `{id}`) as a uuid.
 *
 * @param {string|object|null} value The relation value.
 * @return {string} The uuid, or ''.
 */
function refId(value) {
	return typeof value === 'object' && value !== null ? String(value.id || '') : String(value || '')
}

export default {
	name: 'UrgencyBanner',

	components: { AlertDecagramOutline, CheckDecagramOutline, CnNoteCard, DeclareUrgencyDialog, ExpeditedRoundDialog, NcButton },

	props: {
		/** The decision */
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['refresh'],

	data() {
		return {
			decision: {},
			stages: [],
			bodyName: '',
			meetingLabel: '',
			dialog: '',
			saving: false,
			error: '',
			message: '',
			bodies: [],
			defaultBody: '',
		}
	},

	computed: {
		/** @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi */
		state() {
			return urgencyState(this.decision, this.stages)
		},

		/** @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once */
		reportable() {
			return canReport(this.decision, this.state)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the decision, its route and the ratification's body and meeting.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi
		 */
		async load() {
			if (!this.objectId) return
			try {
				this.decision = (await ensureRelationType('decision').fetchObject('decision', this.objectId)) || {}
				this.stages = this.decision.isUrgent === true
					? (await ensureRelationType('decision-stage').fetchCollection('decision-stage', { decision: this.objectId, _limit: 100 })) || []
					: []
				await this.loadPlacement()
			} catch (e) {
				this.error = e?.message || ''
			}
		},

		/**
		 * The ratifying body's name and the meeting the ratification is on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
		 */
		async loadPlacement() {
			const stage = this.state.stage
			this.bodyName = ''
			this.meetingLabel = ''
			if (!stage) return
			const bodyId = refId(stage.assignedBody)
			if (bodyId) {
				const body = await ensureRelationType('governance-body').fetchObject('governance-body', bodyId)
				this.bodyName = body?.name || body?.title || ''
			}

			const itemId = refId(stage.agendaItem)
			if (!itemId) return
			const item = await ensureRelationType('agenda-item').fetchObject('agenda-item', itemId)
			const meetingId = refId(item?.meeting)
			if (!meetingId) return
			const meeting = await ensureRelationType('meeting').fetchObject('meeting', meetingId)
			if (meeting) {
				this.meetingLabel = meeting.scheduledDate
					? `${meeting.title} (${new Date(meeting.scheduledDate).toLocaleDateString()})`
					: meeting.title
			}
		},

		/**
		 * @param {string} name `declare` or `round`.
		 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
		 */
		openDialog(name) {
			this.error = ''
			this.message = ''
			this.dialog = name
			if (name === 'declare') {
				this.loadBodies()
			}
		},

		/**
		 * The governance bodies a declaration can be made for, with the
		 * decision's own meeting body picked first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office
		 */
		async loadBodies() {
			try {
				const meetingId = refId(this.decision.meeting)
				const meeting = meetingId ? await ensureRelationType('meeting').fetchObject('meeting', meetingId) : null
				this.defaultBody = refId(meeting?.governanceBody)
				const rows = (await ensureRelationType('governance-body').fetchCollection('governance-body', { _limit: 200 })) || []
				this.bodies = rows.map((row) => ({ id: String(row.id || ''), label: row.name || row.title || String(row.id || '') }))
			} catch (e) {
				this.error = e?.message || ''
			}
		},

		/**
		 * Record that the urgent decision was reported to its ratifying body.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
		 */
		async report() {
			await this.submit('urgency/reported', {})
		},

		/**
		 * What to say after an action succeeded.
		 *
		 * @param {string} action The action posted.
		 * @return {string}
		 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
		 */
		successMessage(action) {
			if (action === 'urgency') {
				return this.t('decidiq', 'The decision is urgent. Its ratification has been added to the route.')
			}
			if (action === 'urgency/reported') {
				return this.t('decidiq', 'The report to the ratifying body is recorded.')
			}
			return this.t('decidiq', 'The expedited written round is open.')
		},

		/** @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md */
		closeDialog() {
			this.dialog = ''
			this.error = ''
		},

		/**
		 * Post an action and show the server's answer.
		 *
		 * @param {string} action `urgency`, `urgency/reported` or `expedited-round`.
		 * @param {object} body The request body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
		 */
		async submit(action, body) {
			this.saving = true
			this.error = ''
			try {
				const { ok, data } = await postUrgentAction(String(this.objectId), action, body)
				if (!ok) {
					this.error = data.message || this.t('decidiq', 'The request was refused.')
					return
				}

				this.dialog = ''
				this.message = data.warning || this.successMessage(action)
				await this.load()
				this.$emit('refresh')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.urgency-banner {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.urgency-banner__notice {
	display: flex;
	gap: 12px;
	align-items: flex-start;
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.urgency-banner__notice--awaiting {
	background-color: var(--color-background-hover);
	box-shadow: inset 4px 0 0 0 var(--color-warning);
}

.urgency-banner__notice--awaiting .urgency-banner__icon {
	color: var(--color-warning-text);
}

.urgency-banner__icon {
	flex-shrink: 0;
}

.urgency-banner__reason,
.urgency-banner__warning {
	margin: 4px 0 0;
}

.urgency-banner__warning {
	font-weight: bold;
}

.urgency-banner__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
