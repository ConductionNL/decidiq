<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The Composition widget on a body's page (bodies-board-composition-skills-
 and-diversity, bod-11 and bod-12): a skills matrix of the current members
 against the body's competences with its gaps, and the make-up of the
 members against the targets the body set itself. Both are computed here
 from what the widget loads (src/utils/boardComposition.js); every write
 goes through the guarded competence routes.

 @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
-->
<template>
	<div class="body-composition" data-testid="body-composition-tab">
		<p v-if="loading" class="body-composition__muted">
			{{ t('decidiq', 'Loading the composition…') }}
		</p>
		<p v-else-if="error" class="body-composition__error" role="alert">
			{{ error }}
		</p>
		<template v-else>
			<section class="body-composition__section" :aria-label="t('decidiq', 'Skills matrix')">
				<div class="body-composition__header">
					<h3>{{ t('decidiq', 'Skills matrix') }}</h3>
					<div class="body-composition__actions">
						<NcButton data-testid="body-composition-add-competence" @click="competenceOpen = true">
							{{ t('decidiq', 'Add competence') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="!matrix.rows.length || !matrix.columns.length"
							data-testid="body-composition-record"
							@click="recordOpen = true">
							{{ t('decidiq', 'Record competence') }}
						</NcButton>
					</div>
				</div>
				<p v-if="!matrix.columns.length" class="body-composition__muted">
					{{ t('decidiq', 'This body has not listed the competences it needs yet.') }}
				</p>
				<div v-else class="body-composition__scroll">
					<table class="body-composition__matrix" data-testid="body-composition-matrix">
						<caption class="hidden-visually">
							{{ t('decidiq', 'Members against the competences the body needs') }}
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('decidiq', 'Member') }}
								</th>
								<th v-for="column in matrix.columns" :key="column.id" scope="col">
									{{ column.name }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in matrix.rows" :key="row.seat">
								<th scope="row">
									{{ row.name }}
								</th>
								<td v-for="column in matrix.columns" :key="column.id">
									<template v-if="row.cells[column.id]">
										<span>{{ levelLabel(row.cells[column.id].level) }}</span>
										<span v-if="!row.cells[column.id].confirmed" class="body-composition__unconfirmed">
											{{ t('decidiq', 'unconfirmed') }}
										</span>
										<NcButton
											v-if="!row.cells[column.id].confirmed"
											variant="tertiary"
											:aria-label="t('decidiq', 'Confirm {competence} of {member}', { competence: column.name, member: row.name })"
											:disabled="busy"
											data-testid="body-composition-confirm"
											@click="confirm(row.cells[column.id].id)">
											{{ t('decidiq', 'Confirm') }}
										</NcButton>
									</template>
									<span v-else class="body-composition__muted" :aria-label="t('decidiq', 'Not held')">–</span>
								</td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th scope="row">
									{{ t('decidiq', 'Confirmed experienced or expert') }}
								</th>
								<td
									v-for="gap in matrix.gaps"
									:key="gap.id"
									:class="{ 'body-composition__gap': gap.gap }"
									data-testid="body-composition-holders">
									{{ t('decidiq', '{holders} of {required}', { holders: gap.holders, required: gap.required }) }}
									<strong v-if="gap.gap">{{ t('decidiq', 'Gap') }}</strong>
								</td>
							</tr>
						</tfoot>
					</table>
				</div>
				<p v-if="notice" class="body-composition__notice" role="status">
					{{ notice }}
				</p>
			</section>

			<section class="body-composition__section" :aria-label="t('decidiq', 'Composition figures')">
				<h3>{{ t('decidiq', 'Composition figures') }}</h3>
				<p class="body-composition__muted">
					{{ t('decidiq', 'Counts of the current members, without names. Age bands are worked out today.') }}
				</p>
				<div class="body-composition__figures">
					<table
						v-for="dimension in dimensions"
						:key="dimension.key"
						class="body-composition__figure"
						:data-testid="`body-composition-figure-${dimension.key}`">
						<caption>{{ dimension.label }}</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('decidiq', 'Value') }}
								</th>
								<th scope="col">
									{{ t('decidiq', 'Members') }}
								</th>
								<th scope="col">
									{{ t('decidiq', 'Share') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="entry in figures[dimension.key].values" :key="entry.value">
								<th scope="row">
									{{ valueLabel(dimension.key, entry.value) }}
								</th>
								<td>{{ entry.count }}</td>
								<td>{{ percent(entry.share) }}</td>
							</tr>
							<tr>
								<th scope="row">
									{{ t('decidiq', 'Not recorded') }}
								</th>
								<td>{{ figures[dimension.key].notRecorded }}</td>
								<td />
							</tr>
						</tbody>
					</table>
				</div>
				<h4>{{ t('decidiq', 'Targets') }}</h4>
				<p v-if="!targets.length" class="body-composition__muted">
					{{ t('decidiq', 'This body has set no diversity targets.') }}
				</p>
				<ul v-else class="body-composition__targets" data-testid="body-composition-targets">
					<li v-for="target in targets" :key="`${target.dimension}-${target.value}`">
						{{ t('decidiq', 'At least {minimum} {value} ({dimension}): now {share}', {
							minimum: percent(target.minimumShare),
							value: target.value,
							dimension: dimensionLabel(target.dimension),
							share: percent(target.share),
						}) }}
						<strong :class="target.met ? 'body-composition__met' : 'body-composition__gap'">
							{{ target.met ? t('decidiq', 'met') : t('decidiq', 'not met') }}
						</strong>
					</li>
				</ul>
			</section>
		</template>

		<MemberCompetenceModal
			v-if="recordOpen"
			:seats="matrix.rows"
			:competences="matrix.columns"
			@close="recordOpen = false"
			@saved="onSaved" />
		<BoardCompetenceModal
			v-if="competenceOpen"
			:body-id="String(objectId)"
			@close="competenceOpen = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import BoardCompetenceModal from '../../modals/BoardCompetenceModal.vue'
import MemberCompetenceModal from '../../modals/MemberCompetenceModal.vue'
import {
	compositionFigures,
	currentSeats,
	skillsMatrix,
	targetResults,
} from '../../utils/boardComposition.js'
import { competencePath, competenceRequest } from '../../utils/competenceApi.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'GovernanceBodyCompositionTab',

	components: { BoardCompetenceModal, MemberCompetenceModal, NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			notice: '',
			memberships: [],
			persons: {},
			competences: [],
			held: [],
			diversityTargets: [],
			recordOpen: false,
			competenceOpen: false,
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short */
		matrix() {
			return skillsMatrix(this.memberships, this.persons, this.competences, this.held)
		},

		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets */
		figures() {
			return compositionFigures(this.memberships, this.persons, new Date())
		},

		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets */
		targets() {
			return targetResults(this.figures, this.diversityTargets)
		},

		/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets */
		dimensions() {
			return [
				{ key: 'gender', label: this.t('decidiq', 'Gender') },
				{ key: 'ageBand', label: this.t('decidiq', 'Age band') },
				{ key: 'nationality', label: this.t('decidiq', 'Nationality') },
				{ key: 'independence', label: this.t('decidiq', 'Independence') },
				{ key: 'external', label: this.t('decidiq', 'Sits from outside') },
			]
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load the body's targets, its memberships with their people, its
		 * competences and the competences held on its current seats.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const bodyId = String(this.objectId)
				const [body, memberships, competences] = await Promise.all([
					ensureRelationType('governance-body').fetchObject('governance-body', bodyId),
					ensureRelationType('membership').fetchCollection('membership', { governanceBody: bodyId, _limit: 100 }),
					ensureRelationType('board-competence').fetchCollection('board-competence', { governanceBody: bodyId, _limit: 100 }),
				])
				const seats = currentSeats(memberships || [])
				const personIds = [...new Set(seats.map((seat) => seat.person).filter(Boolean))]
				const [people, held] = await Promise.all([
					Promise.all(personIds.map((id) => ensureRelationType('person').fetchObject('person', id))),
					Promise.all(seats.map((seat) =>
						ensureRelationType('member-competence').fetchCollection('member-competence', { membership: seat.id, _limit: 100 }),
					)),
				])
				const persons = {}
				personIds.forEach((id, i) => {
					persons[id] = people[i] || {}
				})
				this.diversityTargets = body?.diversityTargets || []
				this.memberships = seats
				this.persons = persons
				this.competences = competences || []
				this.held = held.flat().filter(Boolean)
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The composition could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Confirm a member competence through the guarded route: only a
		 * signatory of the body who is not the holder.
		 *
		 * @param {string} id The member competence.
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
		 */
		async confirm(id) {
			this.busy = true
			this.notice = ''
			try {
				await competenceRequest('POST', competencePath('member-competences', id, 'confirm'))
				this.notice = this.t('decidiq', 'The competence is confirmed.')
				await this.refresh()
			} catch (e) {
				this.notice = e.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * After a modal saved: close it and load again.
		 *
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
		 */
		onSaved() {
			this.recordOpen = false
			this.competenceOpen = false
			this.refresh()
		},

		/**
		 * The label of a level.
		 *
		 * @param {string} level basic, experienced or expert.
		 * @return {string} The label.
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
		 */
		levelLabel(level) {
			return {
				basic: this.t('decidiq', 'basic'),
				experienced: this.t('decidiq', 'experienced'),
				expert: this.t('decidiq', 'expert'),
			}[level] || level
		},

		/**
		 * The label of a figure value; stored values are shown as stored.
		 *
		 * @param {string} dimension The figure key.
		 * @param {string} value The value.
		 * @return {string} The label.
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
		 */
		valueLabel(dimension, value) {
			const labels = {
				ageBand: {
					'under-40': this.t('decidiq', 'under 40'),
					'40-54': this.t('decidiq', '40 to 54'),
					'55-69': this.t('decidiq', '55 to 69'),
					'70-plus': this.t('decidiq', '70 and over'),
				},
				external: {
					yes: this.t('decidiq', 'yes'),
					no: this.t('decidiq', 'no'),
				},
			}
			return labels[dimension]?.[value] || value
		},

		/**
		 * The label of a target's dimension.
		 *
		 * @param {string} dimension gender, age-band, nationality or independence.
		 * @return {string} The label.
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
		 */
		dimensionLabel(dimension) {
			const key = dimension === 'age-band' ? 'ageBand' : dimension
			return this.dimensions.find((d) => d.key === key)?.label || dimension
		},

		/**
		 * A share as a whole percentage.
		 *
		 * @param {number} share Between 0 and 1.
		 * @return {string} Such as 33%.
		 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
		 */
		percent(share) {
			return `${Math.round(Number(share) * 100)}%`
		},
	},
}
</script>

<style scoped>
.body-composition {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
}

.body-composition__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.body-composition__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.body-composition__scroll {
	overflow-x: auto;
}

.body-composition table {
	border-collapse: collapse;
}

.body-composition th,
.body-composition td {
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.body-composition__figures {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
}

.body-composition__figure caption {
	font-weight: bold;
	text-align: start;
}

.body-composition__unconfirmed,
.body-composition__muted {
	color: var(--color-text-maxcontrast);
}

.body-composition__gap {
	color: var(--color-error-text);
}

.body-composition__met {
	color: var(--color-success-text);
}

.body-composition__error {
	color: var(--color-error-text);
}
</style>
