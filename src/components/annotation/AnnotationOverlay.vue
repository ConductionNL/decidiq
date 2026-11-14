<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The marks on one rendered PDF page, as an SVG laid over it
 (document-annotations task 5): highlights as translucent rectangles, sticky
 notes as markers, drawings as strokes, each at its page-relative anchor so it
 lands in the same place at any zoom. In note mode a click places a note; in
 drawing mode a drag draws a stroke. The panel is the accessible list of every
 mark; the markers here are focusable too.

 @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
-->
<template>
	<svg
		class="annotation-overlay"
		:class="`annotation-overlay--${mode}`"
		:width="width"
		:height="height"
		:viewBox="`0 0 ${width} ${height}`"
		data-testid="annotation-overlay"
		@click="onClick"
		@pointerdown="onPointerDown"
		@pointermove="onPointerMove"
		@pointerup="onPointerUp">
		<g
			v-for="annotation in annotations"
			:key="annotation.id"
			class="annotation-overlay__mark"
			:class="{
				'annotation-overlay__mark--emphasized':
					annotation.id === emphasizedId,
			}"
			:style="{ '--annotation-color': colorOf(annotation) }"
			:data-testid="`annotation-mark-${annotation.id}`">
			<template v-if="annotation.annotationType === 'highlight'">
				<rect
					v-for="(quad, index) in annotation.quads || []"
					:key="index"
					class="annotation-overlay__highlight"
					v-bind="rectOf(quad)" />
			</template>
			<path
				v-else-if="annotation.annotationType === 'drawing'"
				class="annotation-overlay__stroke"
				:d="pathOf(annotation.path)" />
			<circle
				v-else-if="annotation.point"
				class="annotation-overlay__note"
				:cx="annotation.point.x * width"
				:cy="annotation.point.y * height"
				r="10"
				tabindex="0"
				role="button"
				:aria-label="
					t('decidiq', 'Note on page {page}: {text}', {
						page: annotation.page,
						text: annotation.bodyText || '',
					})
				"
				@click.stop="$emit('select', annotation.id)"
				@keydown.enter.prevent="$emit('select', annotation.id)" />
		</g>
		<path
			v-if="draft.length > 1"
			class="annotation-overlay__stroke annotation-overlay__stroke--draft"
			:d="pathOf(draft)" />
	</svg>
</template>

<script>
import {
	COLORS,
	quadRect,
	relativePoint,
	strokePath,
} from '../../utils/annotations.js'

export default {
	name: 'AnnotationOverlay',

	props: {
		/** The marks to draw on this page. */
		annotations: { type: Array, default: () => [] },
		/** The page's width on screen, in pixels. */
		width: { type: Number, required: true },
		/** The page's height on screen, in pixels. */
		height: { type: Number, required: true },
		/** select, note or draw. */
		mode: { type: String, default: 'select' },
		/** The mark the panel points at. */
		emphasizedId: { type: String, default: '' },
	},

	emits: ['point', 'stroke', 'select'],

	data() {
		return {
			draft: [],
			drawing: false,
		}
	},

	methods: {
		/**
		 * @param {object} annotation The annotation.
		 * @return {string} Its colour variable.
		 */
		colorOf(annotation) {
			return COLORS[annotation.color] || COLORS.yellow
		},

		/**
		 * @param {Array<number>} quad A quad.
		 * @return {object} The SVG rectangle attributes.
		 */
		rectOf(quad) {
			return quadRect(quad, this.width, this.height)
		},

		/**
		 * @param {Array<Array<number>>} path A stroke.
		 * @return {string} The SVG path data.
		 */
		pathOf(path) {
			return strokePath(path, this.width, this.height)
		},

		/**
		 * @param {PointerEvent|MouseEvent} event The event.
		 * @return {{x: number, y: number}} The point relative to the page.
		 */
		pointOf(event) {
			return relativePoint(
				event.clientX,
				event.clientY,
				this.$el.getBoundingClientRect(),
			)
		},

		/**
		 * Place a note where the page is clicked, in note mode.
		 *
		 * @param {MouseEvent} event The click.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		onClick(event) {
			if (this.mode !== 'note') return
			this.$emit('point', this.pointOf(event))
		},

		/**
		 * @param {PointerEvent} event The pointer.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		onPointerDown(event) {
			if (this.mode !== 'draw') return
			this.drawing = true
			this.draft = [[this.pointOf(event).x, this.pointOf(event).y]]
			event.target.setPointerCapture?.(event.pointerId)
		},

		/**
		 * @param {PointerEvent} event The pointer.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		onPointerMove(event) {
			if (!this.drawing) return
			const point = this.pointOf(event)
			this.draft = [...this.draft, [point.x, point.y]]
		},

		/**
		 * Finish a stroke and hand it to the view to confirm.
		 *
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		onPointerUp() {
			if (!this.drawing) return
			this.drawing = false
			if (this.draft.length > 1) this.$emit('stroke', this.draft)
			this.draft = []
		},
	},
}
</script>

<style scoped>
.annotation-overlay {
	position: absolute;
	inset: 0;
	pointer-events: none;
}

.annotation-overlay--note,
.annotation-overlay--draw {
	pointer-events: all;
	cursor: crosshair;
	touch-action: none;
}

.annotation-overlay__highlight {
	fill: var(--annotation-color);
	fill-opacity: 0.35;
}

.annotation-overlay__stroke {
	fill: none;
	stroke: var(--annotation-color, var(--color-primary-element));
	stroke-width: 3;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.annotation-overlay__note {
	fill: var(--annotation-color);
	stroke: var(--color-main-text);
	stroke-width: 1.5;
	pointer-events: all;
	cursor: pointer;
}

.annotation-overlay__note:focus-visible {
	outline: 2px solid var(--color-primary-element);
}

.annotation-overlay__mark--emphasized .annotation-overlay__highlight {
	fill-opacity: 0.6;
	stroke: var(--color-main-text);
	stroke-width: 2;
}

.annotation-overlay__mark--emphasized .annotation-overlay__stroke {
	stroke-width: 6;
}

.annotation-overlay__mark--emphasized .annotation-overlay__note {
	stroke-width: 4;
}
</style>
