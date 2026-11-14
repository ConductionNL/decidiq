<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: voting groups (board DcBeheer, Stemgroepen). Lists
 the groups with their member count and links to the in-app page where the
 secretariat makes and changes them.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.
-->
<template>
	<div class="decidiq-voting-groups" data-testid="voting-groups-settings">
		<h3 class="decidiq-voting-groups__title">
			{{ t('decidiq', 'Voting groups') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'Fixed groups the chair picks when opening a voting round.',
				)
			}}
		</p>

		<table v-if="rows.length > 0" class="decidiq-voting-groups__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidiq', 'Name') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Members') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.id">
					<td>
						<a :href="groupUrl(row.id)">{{ row.name }}</a>
					</td>
					<td>{{ row.memberCount }}</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="loaded">
			{{ t('decidiq', 'No voting groups yet.') }}
		</p>

		<p>
			<a :href="listUrl" class="decidiq-voting-groups__manage">
				{{ t('decidiq', 'Manage voting groups') }}
			</a>
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { votingGroupRows } from '../../utils/votingGroups.js'

export default {
	name: 'VotingGroupsSettings',
	data() {
		return {
			loaded: false,
			rows: [],
		}
	},

	computed: {
		/** @return {string} The in-app list of voting groups. */
		listUrl() {
			return generateUrl('/apps/decidiq/voting-groups')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/** Read the groups from the register. */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/decidiq/voting-group',
					),
					{ params: { _limit: 100, '_order[name]': 'asc' } },
				)
				this.rows = votingGroupRows(data?.results)
			} catch {
				this.rows = []
			} finally {
				this.loaded = true
			}
		},

		/**
		 * The in-app page of one group.
		 *
		 * @param {string} id The group id.
		 * @return {string} The URL.
		 */
		groupUrl(id) {
			return generateUrl('/apps/decidiq/voting-groups/{id}', { id })
		},
	},
}
</script>

<style scoped>
.decidiq-voting-groups {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-voting-groups__title {
	font-weight: bold;
}

.decidiq-voting-groups__table {
	border-collapse: collapse;
	width: 100%;
}

.decidiq-voting-groups__table th,
.decidiq-voting-groups__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-block-end: 1px solid var(--color-border);
	text-align: start;
}

.decidiq-voting-groups__table a,
.decidiq-voting-groups__manage {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
