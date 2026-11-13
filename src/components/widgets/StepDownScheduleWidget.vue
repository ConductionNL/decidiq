<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Step-down schedule (rooster van aftreden) on a governance body's page
 (bodies-term-of-office-and-step-down, bod-07): every membership and every
 position hold of the body whose term ends in the chosen window, soonest
 first, with the position or "Member", the term number, the end date and
 whether the person can be reappointed.

 Read-only and computed on read: the lines are derived in
 src/utils/stepDownSchedule.js from the body's memberships, position holds
 and position types, and nothing is stored (REQ-CTM-008). A custom widget
 because one object-list cannot union two schemas.

 @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
 @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-003-herbenoembaar-follows-the-positions-rules
-->
<template>
	<div class="step-down" data-testid="step-down-schedule">
		<div class="step-down__header">
			<NcSelect
				v-model="selectedWindow"
				class="step-down__window"
				:inputLabel="t('decidiq', 'Period')"
				:options="windowOptions"
				label="label"
				:clearable="false"
				data-testid="step-down-window" />
		</div>
		<p v-if="loading" class="step-down__muted">
			{{ t('decidiq', 'Loading the step-down schedule…') }}
		</p>
		<p v-else-if="error" class="step-down__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="!lines.length" class="step-down__muted">
			{{ t('decidiq', 'No terms end in this period.') }}
		</p>
		<table v-else class="step-down__table" data-testid="step-down-table">
			<caption class="hidden-visually">
				{{
					t('decidiq', 'Step-down schedule')
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidiq', 'Name') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Position') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Term') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Term end date') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Reappointable') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="line in lines"
					:key="line.id"
					:data-kind="line.kind"
					data-testid="step-down-row">
					<th scope="row">
						<router-link
							v-if="line.person"
							:to="{ path: profilePath(line.person) }">
							{{ line.name || t('decidiq', 'Unknown person') }}
						</router-link>
						<span v-else>{{ t('decidiq', 'Unknown person') }}</span>
					</th>
					<td>{{ line.position || t('decidiq', 'Member') }}</td>
					<td>{{ line.termNumber === null ? '' : line.termNumber }}</td>
					<td>{{ formatDate(line.endDate) }}</td>
					<td data-testid="step-down-reappointable">
						{{ reappointableLabel(line.reappointable) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { profilePath } from '../../utils/memberProfile.js'
import {
	REAPPOINTABLE,
	STEP_DOWN_WINDOWS,
	stepDownLines,
	windowUntil,
} from '../../utils/stepDownSchedule.js'
import { ensureRelationType } from '../tabs/useRelationStore.js'

export default {
	name: 'StepDownScheduleWidget',

	components: { NcSelect },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			body: null,
			memberships: [],
			holds: [],
			personsById: {},
			positionTypesById: {},
			selectedWindow: null,
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first */
		windowOptions() {
			const labels = {
				'next-12-months': this.t('decidiq', 'Next 12 months'),
				'this-term': this.t('decidiq', 'This term'),
				all: this.t('decidiq', 'All'),
			}
			return STEP_DOWN_WINDOWS.map((id) => ({ id, label: labels[id] }))
		},

		/** @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first */
		lines() {
			const now = new Date()
			return stepDownLines(
				{
					memberships: this.memberships,
					holds: this.holds,
					personsById: this.personsById,
					positionTypesById: this.positionTypesById,
				},
				{
					until: windowUntil(
						this.selectedWindow?.id || STEP_DOWN_WINDOWS[0],
						this.body,
						now,
					),
					now,
				},
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first */
			handler() {
				this.refresh()
			},
		},
	},

	/** @spec exclude lifecycle hook; only seeds the window select with its default */
	created() {
		this.selectedWindow = this.windowOptions[0]
	},

	methods: {
		/**
		 * The profile route of a person (REQ-MPR-003).
		 *
		 * @param {string} personId The person id.
		 * @return {string}
		 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile
		 */
		profilePath(personId) {
			return profilePath(personId)
		},

		/**
		 * Load the body, its memberships, position holds and position types,
		 * and the people behind them. Reads only.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const bodyId = String(this.objectId)
				const [body, memberships, holds, positionTypes] = await Promise.all([
					ensureRelationType('governance-body').fetchObject(
						'governance-body',
						bodyId,
					),
					ensureRelationType('membership').fetchCollection('membership', {
						governanceBody: bodyId,
						_limit: 500,
					}),
					ensureRelationType('position-hold').fetchCollection(
						'position-hold',
						{ governanceBody: bodyId, _limit: 500 },
					),
					ensureRelationType('position-type').fetchCollection(
						'position-type',
						{ governanceBody: bodyId, _limit: 200 },
					),
				])
				this.body = body || null
				this.memberships = memberships || []
				this.holds = holds || []
				this.positionTypesById = Object.fromEntries(
					(positionTypes || []).map((type) => [type.id, type]),
				)
				this.personsById = await this.fetchPeople(this.memberships)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Could not load the step-down schedule.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The people of the memberships, by id; an unreadable one is left out.
		 *
		 * @param {Array<object>} memberships The memberships.
		 * @return {Promise<{[id: string]: object}>}
		 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
		 */
		async fetchPeople(memberships) {
			const store = ensureRelationType('person')
			const ids = [
				...new Set(memberships.map((m) => m.person).filter(Boolean)),
			]
			const people = await Promise.all(
				ids.map((id) => store.fetchObject('person', id).catch(() => null)),
			)
			return Object.fromEntries(
				ids.map((id, i) => [id, people[i]]).filter(([, person]) => person),
			)
		},

		/**
		 * The Herbenoembaar text of a line.
		 *
		 * @param {string} value One of REAPPOINTABLE.
		 * @return {string}
		 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-003-herbenoembaar-follows-the-positions-rules
		 */
		reappointableLabel(value) {
			if (value === REAPPOINTABLE.no) return this.t('decidiq', 'No')
			if (value === REAPPOINTABLE.lastTerm) {
				return this.t('decidiq', 'Last term')
			}
			return this.t('decidiq', 'Yes')
		},

		/**
		 * A date as the user's short date.
		 *
		 * @param {?string} value An ISO date or date-time.
		 * @return {string} The date, or '' when there is none.
		 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
		 */
		formatDate(value) {
			if (!value) return ''
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.step-down {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.step-down__header {
	display: flex;
	justify-content: flex-end;
}

.step-down__window {
	min-width: 220px;
}

.step-down__table {
	width: 100%;
	border-collapse: collapse;
}

.step-down__table th,
.step-down__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.step-down__muted {
	color: var(--color-text-maxcontrast);
}

.step-down__error {
	color: var(--color-error);
}
</style>
