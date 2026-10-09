<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The Participating organisations widget on a shared body's page
 (bodies-shared-body-participations, bod-13): the secretary adds, edits and
 ends a participation, and each organisation shows how many of its seats
 are filled by members sitting on its behalf. Renders nothing for a body
 that is not a shared body. Rows are computed in
 src/utils/bodyParticipations.js.

 @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
-->
<template>
	<div class="body-participations" data-testid="body-participations-tab">
		<template v-if="shared">
			<div class="body-participations__header">
				<NcButton
					variant="primary"
					data-testid="body-participations-add"
					@click="openDialog(null)">
					{{ t('decidiq', 'Add organisation') }}
				</NcButton>
			</div>
			<p v-if="loading" class="body-participations__muted">
				{{ t('decidiq', 'Loading the participations…') }}
			</p>
			<p v-else-if="error" class="body-participations__error" role="alert">
				{{ error }}
			</p>
			<p v-else-if="!rows.length" class="body-participations__muted">
				{{ t('decidiq', 'No organisations take part in this shared body yet.') }}
			</p>
			<table
				v-else
				class="body-participations__table"
				data-testid="body-participations-table">
				<caption class="hidden-visually">
					{{ t('decidiq', 'Participating organisations') }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Organisation') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Seats') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Voting weight') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Accession date') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{ t('decidiq', 'Actions') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="row.id"
						:class="{ 'body-participations__withdrawn': !row.active }"
						data-testid="body-participations-row">
						<th scope="row">
							{{ row.name }}
							<span v-if="!row.active" class="body-participations__badge">
								{{ t('decidiq', 'Withdrawn on {date}', { date: formatDate(row.exitDate) }) }}
							</span>
						</th>
						<td data-testid="body-participations-seats">
							<template v-if="row.seats !== null">
								{{ t('decidiq', '{filled} of {seats} seats filled', { filled: row.filled, seats: row.seats }) }}
							</template>
							<template v-else>
								{{ t('decidiq', '{filled} seats filled', { filled: row.filled }) }}
							</template>
						</td>
						<td>{{ row.votingWeight !== null ? row.votingWeight : '' }}</td>
						<td>{{ formatDate(row.accessionDate) }}</td>
						<td class="body-participations__actions">
							<NcButton
								variant="tertiary"
								:aria-label="t('decidiq', 'Edit the participation of {name}', { name: row.name })"
								data-testid="body-participations-edit"
								@click="openDialog(row.source)">
								{{ t('decidiq', 'Edit') }}
							</NcButton>
							<NcButton
								v-if="row.active"
								variant="tertiary"
								:disabled="busy"
								:aria-label="t('decidiq', 'End the participation of {name}', { name: row.name })"
								data-testid="body-participations-end"
								@click="end(row.source)">
								{{ t('decidiq', 'End participation') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<BodyParticipationDialog
				v-if="dialogOpen"
				:shared-body-id="String(objectId)"
				:participation="editing"
				:organisation-options="dialogOptions"
				@saved="refresh"
				@close="dialogOpen = false" />
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import BodyParticipationDialog from '../../dialogs/BodyParticipationDialog.vue'
import {
	endParticipationPayload,
	isActiveParticipation,
	isSharedBody,
	participationRows,
} from '../../utils/bodyParticipations.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'BodyParticipationsTab',

	components: { BodyParticipationDialog, NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			shared: false,
			loading: false,
			busy: false,
			error: '',
			participations: [],
			memberships: [],
			bodies: [],
			dialogOpen: false,
			editing: null,
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body */
		bodiesById() {
			return Object.fromEntries(this.bodies.map((body) => [body.id, body]))
		},

		/** @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body */
		rows() {
			return participationRows(
				this.participations,
				this.memberships,
				this.bodiesById,
				new Date(),
			)
		},

		/**
		 * Organisations the dialog offers: every body except this one and
		 * factions; when adding, not the organisations already taking part.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
		 */
		dialogOptions() {
			const self = String(this.objectId)
			const taken = new Set(
				this.participations
					.filter((p) => isActiveParticipation(p))
					.map((p) => p.participant),
			)
			return this.bodies
				.filter((body) => String(body.id) !== self && body.bodyType !== 'faction')
				.filter(
					(body) =>
						this.editing?.participant === body.id || !taken.has(body.id),
				)
				.map((body) => ({ id: body.id, label: body.name || String(body.id) }))
				.sort((a, b) => a.label.localeCompare(b.label))
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load the body, its participations, its memberships and the bodies
		 * that can take part.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const bodyId = String(this.objectId)
				const bodyStore = ensureRelationType('governance-body')
				const body = await bodyStore.fetchObject('governance-body', bodyId)
				this.shared = isSharedBody(body)
				if (!this.shared) return
				const [participations, memberships, bodies] = await Promise.all([
					ensureRelationType('body-participation').fetchCollection(
						'body-participation',
						{ sharedBody: bodyId, _limit: 200 },
					),
					ensureRelationType('membership').fetchCollection('membership', {
						governanceBody: bodyId,
						_limit: 500,
					}),
					bodyStore.fetchCollection('governance-body', { _limit: 500 }),
				])
				this.participations = participations || []
				this.memberships = memberships || []
				this.bodies = bodies || []
			} catch {
				this.error = this.t('decidiq', 'Could not load the participations.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the dialog to add (null) or edit a participation.
		 *
		 * @param {object|null} participation The participation to edit.
		 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
		 */
		openDialog(participation) {
			this.editing = participation
			this.dialogOpen = true
		},

		/**
		 * End a participation as of today; it stays on record.
		 *
		 * @param {object} participation The participation to end.
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
		 */
		async end(participation) {
			this.busy = true
			this.error = ''
			try {
				await ensureRelationType('body-participation').saveObject(
					'body-participation',
					endParticipationPayload(participation, new Date()),
				)
				await this.refresh()
			} catch {
				this.error = this.t('decidiq', 'Could not save the participation.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * A stored date-time as a local date.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The formatted date, or ''.
		 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
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
.body-participations__header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 8px;
}

.body-participations__table {
	width: 100%;
	border-collapse: collapse;
}

.body-participations__table th,
.body-participations__table td {
	padding: 6px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.body-participations__actions {
	white-space: nowrap;
}

.body-participations__withdrawn {
	color: var(--color-text-maxcontrast);
}

.body-participations__badge {
	margin-inline-start: 6px;
	font-size: 0.85em;
	font-weight: normal;
}

.body-participations__muted {
	color: var(--color-text-maxcontrast);
}

.body-participations__error {
	color: var(--color-error);
}
</style>
