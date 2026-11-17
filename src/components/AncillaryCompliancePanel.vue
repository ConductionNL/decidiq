<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 AncillaryCompliancePanel — per-body review status of other positions
 (nevenfuncties), shown above the ancillary-positions index
 (interests-and-integrity, REQ-INT-010, design D3/D7).

 For the chosen governance body it lists every person with an active
 membership and marks who has no position registered, or none reviewed in
 the past 12 months. Computed client-side from two standard OpenRegister list
 queries (memberships and positions of that body); no backend endpoint.
 Assistive only: it is not an audit and blocks nothing.
-->
<template>
	<section class="ancillary-compliance" data-testid="ancillary-compliance-panel">
		<h3 class="ancillary-compliance__title">
			{{ t('decidiq', 'Declaration status per member') }}
		</h3>
		<p class="ancillary-compliance__note">
			{{ t('decidiq', 'For guidance only: computed from the memberships and other positions registered for the chosen body.') }}
		</p>
		<NcSelect
			v-model="body"
			class="ancillary-compliance__body"
			:inputLabel="t('decidiq', 'Governance body')"
			:options="bodyOptions"
			:placeholder="t('decidiq', 'Choose a governance body')"
			label="label"
			:loading="loadingBodies" />
		<p v-if="error" class="ancillary-compliance__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="loading" class="ancillary-compliance__note">
			{{ t('decidiq', 'Loading members…') }}
		</p>
		<template v-else-if="body">
			<p v-if="rows.length === 0" class="ancillary-compliance__note">
				{{ t('decidiq', 'This body has no active members.') }}
			</p>
			<table v-else class="ancillary-compliance__table">
				<caption class="hidden-visually">
					{{ t('decidiq', 'Declaration status per member') }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Member') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Status') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="row.person"
						:class="'ancillary-compliance__row--' + row.status"
						:data-testid="'compliance-row-' + row.status">
						<td>{{ row.name }}</td>
						<td>
							<span class="ancillary-compliance__status">
								{{ statusLabel(row) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import {
	complianceRows,
	refId,
	STATUS_CURRENT,
	STATUS_NONE,
} from '../utils/integrityCompliance.js'
import { ensureRelationType } from './tabs/useRelationStore.js'

/** Upper bound on one body's memberships and positions in one list query. */
const LIST_LIMIT = 500

export default {
	name: 'AncillaryCompliancePanel',

	components: { NcSelect },

	data() {
		return {
			bodies: [],
			loadingBodies: false,
			body: null,
			memberships: [],
			positions: [],
			names: {},
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The governance bodies to choose from.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		bodyOptions() {
			return this.bodies.map((body) => ({
				id: refId(body),
				label: body.name || refId(body),
			}))
		},

		/**
		 * One row per active member of the chosen body.
		 *
		 * @return {Array<object>} The compliance rows.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		rows() {
			return complianceRows(this.memberships, this.positions, Date.now(), this.names)
		},
	},

	watch: {
		body(option) {
			this.loadBody(option ? option.id : '')
		},
	},

	mounted() {
		this.loadBodies()
		this.loadNames()
	},

	methods: {
		/**
		 * The status text of a row (never colour alone).
		 *
		 * @param {{status: string}} row The row.
		 * @return {string} The label.
		 */
		statusLabel(row) {
			if (row.status === STATUS_NONE) return this.t('decidiq', 'No declaration registered')
			if (row.status === STATUS_CURRENT) return this.t('decidiq', 'Up to date')
			return this.t('decidiq', 'Review older than 12 months')
		},

		/**
		 * Fetch the governance bodies for the selector.
		 *
		 * @return {Promise<void>}
		 */
		async loadBodies() {
			this.loadingBodies = true
			try {
				this.bodies = await ensureRelationType('governance-body')
					.fetchCollection('governance-body', { _limit: 200 }) || []
			} catch {
				this.bodies = []
				this.error = this.t('decidiq', 'The governance bodies could not be loaded.')
			} finally {
				this.loadingBodies = false
			}
		},

		/**
		 * Fetch one body's memberships and positions: two list queries.
		 *
		 * @param {string} bodyId The governance body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		async loadBody(bodyId) {
			this.memberships = []
			this.positions = []
			this.error = ''
			if (!bodyId) return
			this.loading = true
			try {
				const [memberships, positions] = await Promise.all([
					ensureRelationType('membership').fetchCollection('membership', {
						governanceBody: bodyId,
						_limit: LIST_LIMIT,
					}),
					ensureRelationType('ancillary-position').fetchCollection('ancillary-position', {
						governanceBody: bodyId,
						_limit: LIST_LIMIT,
					}),
				])
				if (this.body?.id !== bodyId) return
				this.memberships = memberships || []
				this.positions = positions || []
			} catch {
				this.error = this.t('decidiq', 'The declaration status could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Display names of all people, fetched once (not per body), so a
		 * body costs only its two list queries.
		 *
		 * @return {Promise<void>}
		 */
		async loadNames() {
			try {
				const people = await ensureRelationType('person')
					.fetchCollection('person', { _limit: LIST_LIMIT }) || []
				const names = {}
				for (const person of people) {
					if (person?.name) names[refId(person)] = person.name
				}
				this.names = names
			} catch {
				// Names are a nicety: the rows fall back to the person id.
				this.names = {}
			}
		},
	},
}
</script>

<style scoped>
.ancillary-compliance {
	margin: 0 0 calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.ancillary-compliance__title {
	margin: 0 0 var(--default-grid-baseline);
}

.ancillary-compliance__note {
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
}

.ancillary-compliance__error {
	color: var(--color-error-text);
}

.ancillary-compliance__body {
	max-width: 400px;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.ancillary-compliance__table {
	width: 100%;
	border-collapse: collapse;
}

.ancillary-compliance__table th,
.ancillary-compliance__table td {
	text-align: start;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.ancillary-compliance__row--none .ancillary-compliance__status,
.ancillary-compliance__row--stale .ancillary-compliance__status {
	color: var(--color-warning-text);
	font-weight: bold;
}
</style>
