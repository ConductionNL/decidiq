<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Annotate: the PDF papers of a meeting or an agenda item, each with an
 Annotate action that opens the annotator (document-annotations task 7). This
 is the fallback entry design.md names: the library's Documents leaf offers no
 per-row action hook, so the papers are listed here beside it. Other file
 types get no action; plain viewing stays with the Documents leaf.

 @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
-->
<template>
	<div class="document-annotations" data-testid="document-annotations">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="document-annotations__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0" class="document-annotations__empty">
			{{ t('decidiq', 'No PDF papers to annotate on this page.') }}
		</p>
		<ul v-else class="document-annotations__list">
			<li
				v-for="row in rows"
				:key="row.id"
				class="document-annotations__row"
				data-testid="document-annotations-row">
				<span class="document-annotations__name">{{ row.name }}</span>
				<NcButton
					variant="secondary"
					:aria-label="t('decidiq', 'Annotate {name}', { name: row.name })"
					data-testid="document-annotations-open"
					@click="open(row)">
					{{ t('decidiq', 'Annotate') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { pdfRows } from '../../utils/annotations.js'
import { filesUrl } from '../../utils/documentMetadata.js'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'DocumentAnnotationsTab',

	components: { NcButton, NcLoadingIcon },

	/** @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents */
	mixins: [objectIdLoader],

	props: {
		targetType: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
		}
	},

	computed: {
		/** @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents */
		schema() {
			if (this.targetType) return this.targetType
			return String(this.$route?.name || '').startsWith('AgendaItem')
				? 'agenda-item'
				: 'meeting'
		},
	},

	methods: {
		/** @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					filesUrl(this.schema, this.objectId),
				)
				this.rows = pdfRows(data?.results ?? data ?? [])
			} catch {
				this.error = this.t(
					'decidiq',
					'The papers of this page could not be read.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the annotator with the page's meeting or agenda item, so a
		 * note can be shared with the meeting's body (the server takes the
		 * meeting from the agenda item).
		 *
		 * @param {{id: number}} row The paper.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
		 */
		open(row) {
			const query =
				this.schema === 'meeting'
					? { meeting: String(this.objectId) }
					: { agendaItem: String(this.objectId) }
			this.$router.push({
				name: 'AnnotateDocument',
				params: { fileId: String(row.id) },
				query,
			})
		},
	},
}
</script>

<style scoped>
.document-annotations__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.document-annotations__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.document-annotations__empty {
	color: var(--color-text-maxcontrast);
}
</style>
