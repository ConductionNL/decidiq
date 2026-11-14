<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The annotation panel beside the document (document-annotations task 6):
 every annotation the server returned for this document, by page, with its
 author, type, page, visibility and text, filterable by visibility and
 author. An annotation made on another version is labelled "made on v(N)"
 and opens that version instead of being drawn on this one. The panel is the
 accessible form of every mark on the canvas.

 @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
-->
<template>
	<aside
		class="annotation-panel"
		:aria-label="t('decidiq', 'Annotations')"
		data-testid="annotation-panel">
		<h3 class="annotation-panel__title">
			{{ t('decidiq', 'Annotations') }}
			<span class="annotation-panel__count">({{ list.length }})</span>
		</h3>
		<div class="annotation-panel__filters">
			<NcSelect
				v-model="visibilityFilter"
				:inputLabel="t('decidiq', 'Visibility')"
				:options="visibilityChoices"
				:clearable="true"
				label="label"
				data-testid="annotation-panel-visibility" />
			<NcSelect
				v-model="authorFilter"
				:inputLabel="t('decidiq', 'Author')"
				:options="authors"
				:clearable="true"
				data-testid="annotation-panel-author" />
		</div>
		<p v-if="list.length === 0" class="annotation-panel__empty">
			{{ t('decidiq', 'No annotations on this document yet.') }}
		</p>
		<ol v-else class="annotation-panel__list">
			<li v-for="annotation in list" :key="annotation.id">
				<button
					type="button"
					class="annotation-panel__entry"
					:class="{
						'annotation-panel__entry--active':
							annotation.id === activeId,
					}"
					:aria-label="entryLabel(annotation)"
					:data-testid="`annotation-panel-entry-${annotation.id}`"
					@click="$emit('activate', annotation)">
					<span class="annotation-panel__meta">
						<span>{{ typeLabel(annotation.annotationType) }}</span>
						<span>{{
							t('decidiq', 'Page {page}', { page: annotation.page })
						}}</span>
						<span
							class="annotation-panel__badge"
							:data-testid="`annotation-panel-visibility-${annotation.id}`">
							{{ visibilityLabel(annotation.visibility) }}
						</span>
						<span
							v-if="versionOf(annotation)"
							class="annotation-panel__badge annotation-panel__badge--version">
							{{
								t('decidiq', 'made on {version}', {
									version: versionOf(annotation),
								})
							}}
						</span>
					</span>
					<span class="annotation-panel__author">{{
						annotation.author
					}}</span>
					<span v-if="annotation.quote" class="annotation-panel__quote"
						>“{{ annotation.quote }}”</span
					>
					<span
						v-if="annotation.bodyText"
						class="annotation-panel__text"
						>{{ annotation.bodyText }}</span
					>
				</button>
				<NcButton
					v-if="annotation.author === userId"
					variant="tertiary"
					:aria-label="t('decidiq', 'Delete annotation')"
					:data-testid="`annotation-panel-delete-${annotation.id}`"
					@click="$emit('delete', annotation)">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
				</NcButton>
			</li>
		</ol>
	</aside>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import {
	authorsOf,
	otherVersionLabel,
	panelList,
	visibilityOptions,
} from '../../utils/annotations.js'

export default {
	name: 'AnnotationPanel',

	components: { NcButton, NcSelect, TrashCanOutline },

	props: {
		/** The annotations the server returned. */
		annotations: { type: Array, default: () => [] },
		/** The version on screen. */
		fileVersion: { type: String, default: '' },
		/** The signed-in user. */
		userId: { type: String, default: '' },
		/** The entry the user last activated. */
		activeId: { type: String, default: '' },
	},

	emits: ['activate', 'delete'],

	data() {
		return {
			visibilityFilter: null,
			authorFilter: null,
		}
	},

	computed: {
		/** @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents */
		visibilityChoices() {
			return visibilityOptions(true, this.t)
		},

		/** @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents */
		authors() {
			return authorsOf(this.annotations)
		},

		/** @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents */
		list() {
			return panelList(this.annotations, {
				visibility: this.visibilityFilter?.id || '',
				author: this.authorFilter || '',
			})
		},
	},

	methods: {
		/**
		 * @param {string} type The annotation type.
		 * @return {string} Its name.
		 */
		typeLabel(type) {
			if (type === 'highlight') return this.t('decidiq', 'Highlight')
			if (type === 'drawing') return this.t('decidiq', 'Drawing')
			return this.t('decidiq', 'Sticky note')
		},

		/**
		 * @param {string} visibility The visibility.
		 * @return {string} Its name.
		 */
		visibilityLabel(visibility) {
			const option = this.visibilityChoices.find(
				(choice) => choice.id === visibility,
			)
			return option ? option.label : this.t('decidiq', 'Private')
		},

		/**
		 * @param {object} annotation The annotation.
		 * @return {string|null} The label of its version when it is not the one shown.
		 */
		versionOf(annotation) {
			return otherVersionLabel(annotation, this.fileVersion)
		},

		/**
		 * @param {object} annotation The annotation.
		 * @return {string} What a screen reader announces.
		 */
		entryLabel(annotation) {
			return this.t('decidiq', '{type} by {author} on page {page}', {
				type: this.typeLabel(annotation.annotationType),
				author: annotation.author,
				page: annotation.page,
			})
		},
	},
}
</script>

<style scoped>
.annotation-panel {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	border-inline-start: 1px solid var(--color-border);
	overflow-y: auto;
}

.annotation-panel__title {
	margin: 0;
}

.annotation-panel__count,
.annotation-panel__author,
.annotation-panel__empty {
	color: var(--color-text-maxcontrast);
}

.annotation-panel__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.annotation-panel__list li {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
}

.annotation-panel__entry {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);
	border: none;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.annotation-panel__entry:focus-visible {
	outline: 2px solid var(--color-primary-element);
}

.annotation-panel__entry--active {
	background: var(--color-primary-element-light);
}

.annotation-panel__meta {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	font-size: var(--font-size-small, 13px);
}

.annotation-panel__badge {
	padding: 0 var(--default-grid-baseline);
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.annotation-panel__badge--version {
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
}

.annotation-panel__quote {
	font-style: italic;
}
</style>
