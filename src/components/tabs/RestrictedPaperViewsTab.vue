<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 Who opened the papers of a confidentiality restriction (embargo-geheimhouding,
 REQ-EMB-007): every opening of a paper under an active restriction, newest
 first, read from GET /api/confidentiality/restrictions/{id}/views. The server
 lets only the imposing body's staff read the list; anyone else sees the
 refusal it answers. The rules live in src/utils/restrictedPaperViews.js.
 @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
-->
<template>
	<div class="restricted-paper-views" data-testid="restricted-paper-views">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p
			v-else-if="error"
			class="restricted-paper-views__hint"
			role="alert"
			data-testid="restricted-paper-views-error">
			{{ error }}
		</p>
		<p
			v-else-if="rows.length === 0"
			class="restricted-paper-views__hint"
			data-testid="restricted-paper-views-none">
			{{ t('decidiq', 'No one has opened these papers yet.') }}
		</p>
		<table v-else class="restricted-paper-views__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidiq', 'Person') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Opened') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Paper') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Agenda item') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.key"
					data-testid="restricted-paper-views-row">
					<td>{{ row.reader }}</td>
					<td>
						<time :datetime="row.at">{{ time(row.at) }}</time>
					</td>
					<td>{{ row.paper }}</td>
					<td>{{ row.agendaItem }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcLoadingIcon } from '@nextcloud/vue'
import { viewRows, viewTime, viewsRefusal, viewsUrl } from '../../utils/restrictedPaperViews.js'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'RestrictedPaperViewsTab',

	components: { NcLoadingIcon },

	/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding */
	mixins: [objectIdLoader],

	data() {
		return { loading: false, rows: [], error: '' }
	},

	methods: {
		/**
		 * Read the openings of this restriction's papers.
		 *
		 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(viewsUrl(this.objectId))
				this.rows = viewRows(response.data?.views)
			} catch (e) {
				this.rows = []
				this.error = viewsRefusal(e, this.t('decidiq', 'The openings could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * The time of an opening in the reader's locale.
		 *
		 * @param {string} at The ISO 8601 time.
		 * @return {string} The time.
		 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
		 */
		time(at) {
			return viewTime(at)
		},
	},
}
</script>

<style scoped>
.restricted-paper-views__hint {
	color: var(--color-text-maxcontrast);
}

.restricted-paper-views__table {
	width: 100%;
	border-collapse: collapse;
}

.restricted-paper-views__table th,
.restricted-paper-views__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	overflow-wrap: anywhere;
}
</style>
