<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The decisions citing a governing document (governing-documents-register,
 REQ-GDR-005): read from decidiq's cited-by endpoint, which reads the document
 and every citing decision with the user's own rights. Each decision links to
 its detail page and shows the articles it cites.

 @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
-->
<template>
	<div class="gd-cited-by" data-testid="governing-document-cited-by">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="gd-cited-by__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="decisions.length === 0" class="gd-cited-by__empty">
			{{ t('decidiq', 'No decision cites this governing document yet.') }}
		</p>
		<ul v-else class="gd-cited-by__list">
			<li
				v-for="decision in decisions"
				:key="decision.id"
				class="gd-cited-by__row"
				data-testid="governing-document-cited-by-row">
				<router-link
					:to="{ name: 'DecisionDetail', params: { id: decision.id } }"
					class="gd-cited-by__link">
					{{ decision.title || decision.id }}
				</router-link>
				<span v-if="decision.articles.length" class="gd-cited-by__meta">
					{{ decision.articles.join(', ') }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import { citedByPath } from '../../utils/governingDocumentCitations.js'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'GoverningDocumentCitedByTab',
	components: { NcLoadingIcon },
	mixins: [objectIdLoader],
	data() {
		return {
			loading: false,
			error: '',
			decisions: [],
		}
	},

	methods: {
		/**
		 * Read the decisions citing this document.
		 *
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(citedByPath(String(this.objectId))))
				const rows = Array.isArray(data?.results) ? data.results : []
				this.decisions = rows.map((row) => ({
					id: String(row.id || ''),
					title: String(row.title || ''),
					articles: Array.isArray(row.articles) ? row.articles : [],
				})).filter((row) => row.id !== '')
			} catch (e) {
				this.error = e?.response?.data?.message || this.t('decidiq', 'The citing decisions could not be read.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.gd-cited-by__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.gd-cited-by__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.gd-cited-by__link {
	font-weight: 600;
	color: var(--color-primary-element);
	text-decoration: underline;
	overflow-wrap: anywhere;
}

.gd-cited-by__link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.gd-cited-by__meta,
.gd-cited-by__empty {
	color: var(--color-text-maxcontrast);
}

.gd-cited-by__error {
	color: var(--color-error-text);
}
</style>
