<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The annotator (document-annotations tasks 5 and 6): a PDF of a meeting or an
 agenda item rendered page by page with pdf.js, a decidiq-owned SVG overlay on
 every page, and the annotation panel beside it. Select text and choose
 Highlight, place a sticky note, or draw; each is saved with page-relative
 anchors and comes back in the same place after a reload.

 Plain viewing stays with the Nextcloud viewer; this page is only for
 annotating, and pdf.js is loaded only when it opens (a chunk of its own).
 Annotations made on another version of the file are listed with their
 version and never drawn on this one; choosing one opens its own version
 from Nextcloud's file versions when it is still there.

 Route: /annotate/:fileId, with meeting and agendaItem in the query when
 opened from a meeting or agenda item page, and version to show an earlier
 version.

 @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
-->
<template>
	<div class="annotation-view" data-testid="annotation-view">
		<header class="annotation-view__header">
			<h2 class="annotation-view__title">
				{{ store.name || t('decidiq', 'Annotate document') }}
				<span v-if="shownLabel" class="annotation-view__version">{{
					shownLabel
				}}</span>
			</h2>
			<div
				class="annotation-view__modes"
				role="toolbar"
				:aria-label="t('decidiq', 'Annotation tools')">
				<NcButton
					v-for="tool in tools"
					:key="tool.mode"
					:variant="mode === tool.mode ? 'primary' : 'secondary'"
					:aria-pressed="mode === tool.mode ? 'true' : 'false'"
					:disabled="viewingOtherVersion"
					:data-testid="`annotation-mode-${tool.mode}`"
					@click="setMode(tool.mode)">
					{{ tool.label }}
				</NcButton>
			</div>
		</header>

		<CnNoteCard
			v-if="viewingOtherVersion"
			type="info"
			data-testid="annotation-other-version">
			{{
				t(
					'decidiq',
					'You are viewing version {version}. Its annotations are shown where they were made.',
					{ version: shownLabel },
				)
			}}
			<NcButton variant="tertiary" @click="showVersion('')">
				{{ t('decidiq', 'Back to the current version') }}
			</NcButton>
		</CnNoteCard>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'The document could not be opened')">
			{{ error }}
		</CnNoteCard>

		<div class="annotation-view__body">
			<div
				ref="pages"
				class="annotation-view__pages"
				@mouseup="onSelectionEnd">
				<NcLoadingIcon v-if="store.loading || rendering" :size="32" />
				<div
					v-for="page in pages"
					:key="`${shownVersion}-${page.number}`"
					:ref="`page-${page.number}`"
					class="annotation-view__page"
					:data-page="page.number"
					:style="{
						width: `${page.width}px`,
						height: `${page.height}px`,
						'--scale-factor': scale,
						'--total-scale-factor': scale,
					}">
					<canvas
						:ref="`canvas-${page.number}`"
						class="annotation-view__canvas" />
					<div
						:ref="`text-${page.number}`"
						class="annotation-view__text textLayer" />
					<AnnotationOverlay
						:annotations="marksOn(page.number)"
						:width="page.width"
						:height="page.height"
						:mode="viewingOtherVersion ? 'select' : mode"
						:emphasizedId="activeId"
						@point="startNote(page.number, $event)"
						@stroke="startDrawing(page.number, $event)"
						@select="activeId = $event" />
				</div>
			</div>

			<AnnotationPanel
				:annotations="store.annotations"
				:fileVersion="shownVersion"
				:userId="userId"
				:activeId="activeId"
				@activate="activate"
				@delete="deleteTarget = $event" />
		</div>

		<form
			v-if="draft"
			class="annotation-view__composer"
			data-testid="annotation-composer"
			@submit.prevent="save">
			<p class="annotation-view__composer-title">
				{{
					t('decidiq', 'New {type} on page {page}', {
						type: draftTypeLabel,
						page: draft.page,
					})
				}}
			</p>
			<blockquote v-if="draft.quote" class="annotation-view__quote">
				“{{ draft.quote }}”
			</blockquote>
			<NcTextArea
				v-model="bodyText"
				:label="t('decidiq', 'Note')"
				:required="draft.annotationType === 'sticky-note'"
				data-testid="annotation-composer-text" />
			<NcSelect
				v-model="visibility"
				:inputLabel="t('decidiq', 'Who can see this')"
				:options="visibilityChoices"
				:clearable="false"
				label="label"
				data-testid="annotation-composer-visibility" />
			<NcSelect
				v-model="color"
				:inputLabel="t('decidiq', 'Colour')"
				:options="colorChoices"
				:clearable="false"
				label="label"
				data-testid="annotation-composer-color" />
			<p v-if="saveError" class="annotation-view__error" role="alert">
				{{ saveError }}
			</p>
			<div class="annotation-view__composer-actions">
				<NcButton @click="cancelDraft">
					{{ t('decidiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="
						saving
						|| (draft.annotationType === 'sticky-note'
							&& !bodyText.trim())
					"
					data-testid="annotation-composer-save">
					{{ t('decidiq', 'Save annotation') }}
				</NcButton>
			</div>
		</form>

		<AnnotationDeleteDialog
			v-if="deleteTarget"
			:title="t('decidiq', 'Delete annotation')"
			:message="
				t(
					'decidiq',
					'Delete this annotation? Anyone it was shared with will no longer see it.',
				)
			"
			:busy="deleting"
			:error="deleteError"
			@confirm="confirmDelete"
			@close="deleteTarget = null" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon, NcSelect, NcTextArea } from '@nextcloud/vue'
import AnnotationOverlay from '../components/annotation/AnnotationOverlay.vue'
import AnnotationPanel from '../components/annotation/AnnotationPanel.vue'
import AnnotationDeleteDialog from '../modals/AnnotationDeleteDialog.vue'
import { useAnnotationStore } from '../store/annotation.js'
import {
	davUrl,
	drawnOnPage,
	quadsFromRects,
	thinStroke,
	versionUrl,
	visibilityOptions,
} from '../utils/annotations.js'

/**
 * How large a page is drawn, in CSS pixels per PDF point.
 */
const SCALE = 1.4

/**
 * pdf.js, loaded the first time the annotator opens.
 *
 * @return {Promise<object>} The pdf.js module.
 */
async function loadPdfJs() {
	const pdfjs = await import(/* webpackChunkName: "annotator" */ 'pdfjs-dist')
	if (!pdfjs.GlobalWorkerOptions.workerPort) {
		pdfjs.GlobalWorkerOptions.workerPort = new Worker(
			new URL('../utils/pdfWorker.js', import.meta.url),
			{ type: 'module' },
		)
	}
	return pdfjs
}

export default {
	name: 'AnnotationView',

	components: {
		AnnotationDeleteDialog,
		AnnotationOverlay,
		AnnotationPanel,
		CnNoteCard,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
	},

	data() {
		return {
			pages: [],
			scale: SCALE,
			rendering: false,
			renderError: '',
			mode: 'select',
			activeId: '',
			draft: null,
			bodyText: '',
			visibility: null,
			color: null,
			saving: false,
			saveError: '',
			deleteTarget: null,
			deleting: false,
			deleteError: '',
		}
	},

	computed: {
		/** @return {object} The annotation store. */
		store() {
			return useAnnotationStore()
		},

		/** @return {number} The file being annotated. */
		fileId() {
			return Number(this.$route?.params?.fileId || 0)
		},

		/** @return {string} The signed-in user. */
		userId() {
			return getCurrentUser()?.uid || ''
		},

		/** @return {string} The version on screen: the one asked for, else the current one. */
		shownVersion() {
			return String(
				this.$route?.query?.version || this.store.fileVersion || '',
			)
		},

		/** @return {boolean} Whether an earlier version is on screen. */
		viewingOtherVersion() {
			return (
				this.store.fileVersion !== ''
				&& this.shownVersion !== this.store.fileVersion
			)
		},

		/** @return {string} The label of the version on screen. */
		shownLabel() {
			if (!this.viewingOtherVersion) return this.store.versionLabel
			const made = this.store.annotations.find(
				(annotation) => String(annotation.fileVersion) === this.shownVersion,
			)
			return made?.versionLabel || ''
		},

		/** @return {object} The meeting and agenda item the annotator was opened from. */
		context() {
			const context = {}
			for (const key of ['meeting', 'agendaItem']) {
				const value = this.$route?.query?.[key]
				if (value) context[key] = String(value)
			}
			return context
		},

		/**
		 * The visibility choices: the faction only for someone in one, and the
		 * whole body only when the annotator knows the meeting.
		 *
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
		 * @return {Array<object>} The options.
		 */
		visibilityChoices() {
			return visibilityOptions(this.store.canShareWithFaction, this.t).filter(
				(option) =>
					option.id !== 'body' || Object.keys(this.context).length > 0,
			)
		},

		/** @return {Array<object>} The colours. */
		colorChoices() {
			return [
				{ id: 'yellow', label: this.t('decidiq', 'Yellow') },
				{ id: 'blue', label: this.t('decidiq', 'Blue') },
				{ id: 'red', label: this.t('decidiq', 'Red') },
				{ id: 'green', label: this.t('decidiq', 'Green') },
			]
		},

		/** @return {Array<object>} The tool buttons. */
		tools() {
			return [
				{ mode: 'select', label: this.t('decidiq', 'Select text') },
				{ mode: 'note', label: this.t('decidiq', 'Place a note') },
				{ mode: 'draw', label: this.t('decidiq', 'Draw') },
			]
		},

		/** @return {string} The kind of the annotation being made. */
		draftTypeLabel() {
			if (this.draft?.annotationType === 'highlight')
				return this.t('decidiq', 'highlight')
			if (this.draft?.annotationType === 'drawing')
				return this.t('decidiq', 'drawing')
			return this.t('decidiq', 'sticky note')
		},

		/** @return {string} The load or render failure. */
		error() {
			return this.store.error || this.renderError
		},
	},

	watch: {
		fileId: {
			immediate: true,
			/** Load the annotations and render the current version. */
			async handler() {
				if (!this.fileId) return
				await this.store.load(this.fileId)
				if (!this.store.error) await this.render()
			},
		},

		/** Render again when another version is asked for. */
		'$route.query.version': function () {
			this.render()
		},
	},

	methods: {
		/**
		 * The marks to draw on a page of the version on screen.
		 *
		 * @param {number} page The page number.
		 * @return {Array<object>} The annotations.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
		 */
		marksOn(page) {
			return drawnOnPage(this.store.annotations, page, this.shownVersion)
		},

		/**
		 * Fetch the version on screen and draw its pages, each with its text
		 * layer so text can be selected.
		 *
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
		 */
		async render() {
			if (!this.store.path || !this.userId) return
			this.rendering = true
			this.renderError = ''
			this.pages = []
			try {
				const url = this.viewingOtherVersion
					? versionUrl(this.userId, this.fileId, this.shownVersion)
					: davUrl(this.userId, this.store.path)
				const response = await fetch(url, {
					headers: { requesttoken: getRequestToken() },
				})
				if (!response.ok) {
					throw new Error(
						this.t(
							'decidiq',
							'This version of the document is no longer available.',
						),
					)
				}

				const pdfjs = await loadPdfJs()
				const pdf = await pdfjs.getDocument({
					data: await response.arrayBuffer(),
					isEvalSupported: false,
				}).promise
				const pages = []
				for (let number = 1; number <= pdf.numPages; number++) {
					const page = await pdf.getPage(number)
					const viewport = page.getViewport({ scale: this.scale })
					pages.push({
						number,
						width: Math.floor(viewport.width),
						height: Math.floor(viewport.height),
						page,
						viewport,
					})
				}
				this.pages = pages.map(({ number, width, height }) => ({
					number,
					width,
					height,
				}))
				await this.$nextTick()
				for (const { number, page, viewport } of pages) {
					await this.drawPage(pdfjs, number, page, viewport)
				}
			} catch (error) {
				this.renderError =
					error?.message
					|| this.t('decidiq', 'The document could not be shown.')
			} finally {
				this.rendering = false
			}
		},

		/**
		 * Draw one page onto its canvas and lay its text over it.
		 *
		 * @param {object} pdfjs The pdf.js module.
		 * @param {number} number The page number.
		 * @param {object} page The pdf.js page.
		 * @param {object} viewport Its viewport.
		 */
		async drawPage(pdfjs, number, page, viewport) {
			const canvas = this.refOf(`canvas-${number}`)
			const text = this.refOf(`text-${number}`)
			if (!canvas || !text) return
			canvas.width = Math.floor(viewport.width)
			canvas.height = Math.floor(viewport.height)
			await page.render({ canvasContext: canvas.getContext('2d'), viewport })
				.promise
			text.replaceChildren()
			const layer = new pdfjs.TextLayer({
				textContentSource: page.streamTextContent(),
				container: text,
				viewport,
			})
			await layer.render()
		},

		/**
		 * @param {string} name A template ref.
		 * @return {HTMLElement|null} The element.
		 */
		refOf(name) {
			const ref = this.$refs[name]
			return Array.isArray(ref) ? ref[0] || null : ref || null
		},

		/**
		 * @param {string} mode select, note or draw.
		 */
		setMode(mode) {
			this.mode = mode
			this.cancelDraft()
		},

		/**
		 * A text selection on a page becomes a highlight to confirm.
		 *
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		onSelectionEnd() {
			if (this.mode !== 'select' || this.viewingOtherVersion) return
			const selection = window.getSelection?.()
			if (!selection || selection.isCollapsed || selection.rangeCount === 0)
				return
			const element = selection.anchorNode?.parentElement?.closest?.(
				'.annotation-view__page',
			)
			if (!element) return
			const quads = quadsFromRects(
				selection.getRangeAt(0).getClientRects(),
				element.getBoundingClientRect(),
			)
			if (quads.length === 0) return
			this.openDraft({
				annotationType: 'highlight',
				page: Number(element.dataset.page),
				quads,
				quote: selection.toString().trim().slice(0, 2000),
			})
		},

		/**
		 * @param {number} page The page.
		 * @param {{x: number, y: number}} point Where the note goes.
		 */
		startNote(page, point) {
			this.openDraft({ annotationType: 'sticky-note', page, point })
		},

		/**
		 * @param {number} page The page.
		 * @param {Array<Array<number>>} path The stroke.
		 */
		startDrawing(page, path) {
			const thinned = thinStroke(path)
			if (thinned.length > 1)
				this.openDraft({ annotationType: 'drawing', page, path: thinned })
		},

		/**
		 * @param {object} draft The anchor of the annotation to make.
		 */
		openDraft(draft) {
			this.draft = draft
			this.bodyText = ''
			this.saveError = ''
			this.visibility = this.visibilityChoices[0]
			this.color = this.colorChoices[0]
		},

		/** Drop the annotation being made. */
		cancelDraft() {
			this.draft = null
			window.getSelection?.()?.removeAllRanges?.()
		},

		/**
		 * Save the annotation being made, private unless chosen otherwise.
		 *
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		async save() {
			if (!this.draft) return
			this.saving = true
			this.saveError = ''
			try {
				const body = {
					...this.draft,
					...this.context,
					visibility: this.visibility?.id || 'private',
					color: this.color?.id || 'yellow',
				}
				if (this.bodyText.trim() !== '') body.bodyText = this.bodyText.trim()
				const stored = await this.store.create(body)
				this.activeId = stored.id
				this.cancelDraft()
			} catch (error) {
				this.saveError = error.message
			} finally {
				this.saving = false
			}
		},

		/**
		 * Go to an annotation: its page and mark, or its own version when it
		 * was made on another one.
		 *
		 * @param {object} annotation The annotation.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
		 */
		async activate(annotation) {
			this.activeId = annotation.id
			if (String(annotation.fileVersion) !== this.shownVersion) {
				this.showVersion(String(annotation.fileVersion))
				return
			}
			this.refOf(`page-${annotation.page}`)?.scrollIntoView?.({
				behavior: 'smooth',
				block: 'start',
			})
		},

		/**
		 * @param {string} version The version to show; empty for the current one.
		 */
		showVersion(version) {
			const query = { ...(this.$route?.query || {}) }
			if (version && version !== this.store.fileVersion) {
				query.version = version
			} else {
				delete query.version
			}
			this.$router.replace({ query })
		},

		/** Delete the annotation the panel asked for. */
		async confirmDelete() {
			this.deleting = true
			this.deleteError = ''
			try {
				await this.store.remove(this.deleteTarget.id)
				this.deleteTarget = null
			} catch (error) {
				this.deleteError = error.message
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>

<style scoped>
.annotation-view {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	height: 100%;
	padding: calc(var(--default-grid-baseline) * 2);
}

.annotation-view__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.annotation-view__title {
	margin: 0;
}

.annotation-view__version {
	margin-inline-start: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.annotation-view__modes {
	display: flex;
	gap: var(--default-grid-baseline);
}

.annotation-view__body {
	display: grid;
	flex: 1;
	grid-template-columns: minmax(0, 1fr) minmax(260px, 340px);
	min-height: 0;
}

.annotation-view__pages {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2);
	overflow: auto;
	background: var(--color-background-dark);
}

.annotation-view__page {
	position: relative;
	flex: none;
	background: var(--color-main-background);
	box-shadow: 0 0 4px var(--color-box-shadow);
}

.annotation-view__canvas {
	position: absolute;
	inset: 0;
}

.annotation-view__text {
	position: absolute;
	inset: 0;
	overflow: hidden;
	line-height: 1;
	opacity: 1;
}

.annotation-view__text :deep(span),
.annotation-view__text :deep(br) {
	position: absolute;
	color: transparent;
	white-space: pre;
	cursor: text;
	transform-origin: 0% 0%;
}

.annotation-view__text :deep(::selection) {
	background: var(--color-primary-element-light);
}

.annotation-view__composer {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 640px;
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.annotation-view__composer-title {
	margin: 0;
	font-weight: bold;
}

.annotation-view__quote {
	margin: 0;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.annotation-view__composer-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
}

.annotation-view__error {
	color: var(--color-error-text, var(--color-error));
}

@media (max-width: 900px) {
	.annotation-view__body {
		grid-template-columns: 1fr;
	}
}
</style>
