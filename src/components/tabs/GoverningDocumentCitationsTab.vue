<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The governing documents a decision cites (governing-documents-register,
 REQ-GDR-005): each citation links to the governing-document page, a secretary
 adds one by choosing the document and, optionally, the article, and removes
 one again. The citation is assistive: it never blocks a decision workflow,
 so a failed save only shows here. The entries are the decision's own
 `citesGoverningDocuments`, saved through the OpenRegister object API.
 The pure logic lives in src/utils/governingDocumentCitations.js.

 @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
-->
<template>
	<div class="gd-citations" data-testid="governing-document-citations">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="gd-citations__error" role="alert">
			{{ error }}
		</p>
		<template v-else>
			<p v-if="citations.length === 0" class="gd-citations__empty">
				{{ t('decidiq', 'This decision cites no governing document.') }}
			</p>
			<ul v-else class="gd-citations__list">
				<li
					v-for="(citation, index) in citations"
					:key="`${citation.document}-${citation.version}-${citation.article}-${index}`"
					class="gd-citations__row"
					data-testid="governing-document-citation">
					<span class="gd-citations__text">
						<router-link
							:to="{ name: 'GoverningDocumentDetail', params: { id: citation.document } }"
							class="gd-citations__link">
							{{ labelOf(citation.document) }}
						</router-link>
						<span v-if="citation.article" class="gd-citations__meta">
							{{ citation.article }}
						</span>
						<span v-if="citation.version" class="gd-citations__meta">
							{{ t('decidiq', 'pinned version') }}
						</span>
					</span>
					<NcButton
						variant="tertiary"
						:disabled="saving"
						:aria-label="t('decidiq', 'Remove the citation of {name}', { name: labelOf(citation.document) })"
						data-testid="governing-document-citation-remove"
						@click="remove(index)">
						{{ t('decidiq', 'Remove') }}
					</NcButton>
				</li>
			</ul>

			<form class="gd-citations__add" @submit.prevent="add">
				<NcSelect
					v-model="chosen"
					:options="options"
					label="label"
					:inputLabel="t('decidiq', 'Governing document')"
					:placeholder="t('decidiq', 'Choose a governing document')"
					data-testid="governing-document-citation-document" />
				<NcTextField
					v-model="article"
					:label="t('decidiq', 'Article (optional)')"
					data-testid="governing-document-citation-article" />
				<NcButton
					type="submit"
					:disabled="saving || !chosen"
					data-testid="governing-document-citation-add">
					{{ t('decidiq', 'Add citation') }}
				</NcButton>
			</form>
			<p v-if="saveError" class="gd-citations__error" role="alert">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	citationsOf,
	documentLabel,
	referenceId,
	withCitation,
	withoutCitation,
} from '../../utils/governingDocumentCitations.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'GoverningDocumentCitationsTab',
	components: { NcButton, NcLoadingIcon, NcSelect, NcTextField },
	mixins: [objectIdLoader],
	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			saveError: '',
			decision: null,
			documents: [],
			chosen: null,
			article: '',
		}
	},

	computed: {
		/**
		 * The decision's citations.
		 *
		 * @return {Array<object>} The citations.
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		citations() {
			return citationsOf(this.decision)
		},

		/**
		 * The governing documents to choose from.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		options() {
			return this.documents.map((document) => ({
				id: referenceId(document),
				label: documentLabel(document),
			}))
		},
	},

	methods: {
		/**
		 * Read the decision and the governing documents the user may see.
		 *
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const [decision, documents] = await Promise.all([
					ensureRelationType('decision').fetchObject('decision', this.objectId),
					ensureRelationType('governing-document').fetchCollection('governing-document', { _limit: 200 }),
				])
				this.decision = decision || null
				this.documents = documents || []
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The citations of this decision could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The name of a cited document.
		 *
		 * @param {string} id The document uuid.
		 * @return {string} Its citation title, or its id.
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		labelOf(id) {
			return documentLabel(this.documents.find((document) => referenceId(document) === id) || { id })
		},

		/**
		 * Add the chosen document and article as a citation.
		 *
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		async add() {
			if (!this.chosen) return
			const saved = await this.store(withCitation(this.citations, { document: this.chosen.id, article: this.article }))
			if (saved) {
				this.chosen = null
				this.article = ''
			}
		},

		/**
		 * Remove the citation at an index.
		 *
		 * @param {number} index The index.
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		async remove(index) {
			await this.store(withoutCitation(this.citations, index))
		},

		/**
		 * Save the decision with these citations.
		 *
		 * @param {Array<object>} citations The citations to store.
		 * @return {Promise<boolean>} Whether it was saved.
		 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations
		 */
		async store(citations) {
			this.saving = true
			this.saveError = ''
			try {
				const saved = await ensureRelationType('decision').saveObject('decision', {
					...this.decision,
					citesGoverningDocuments: citations,
				})
				this.decision = saved || { ...this.decision, citesGoverningDocuments: citations }
				return true
			} catch (e) {
				this.saveError = e?.message || this.t('decidiq', 'The citation could not be saved.')
				return false
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.gd-citations {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.gd-citations__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.gd-citations__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.gd-citations__text {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
}

.gd-citations__link {
	font-weight: 600;
	color: var(--color-primary-element);
	text-decoration: underline;
	overflow-wrap: anywhere;
}

.gd-citations__link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.gd-citations__meta,
.gd-citations__empty {
	color: var(--color-text-maxcontrast);
}

.gd-citations__add {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.gd-citations__error {
	color: var(--color-error-text);
}
</style>
