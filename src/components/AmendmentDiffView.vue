<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Visual word-level diff between the original (parent motion) text and an
 amendment's proposed text (motion-amendment spec): additions in green,
 removals in red. Never colour-only (WCAG 1.4.1): additions are also
 underlined <ins> elements, removals struck-through <del> elements, and a
 legend names both markers.

 Purely presentational — the diff itself comes from src/utils/textDiff.js.

 @spec openspec/specs/motion-amendment/spec.md
-->
<template>
	<div class="amendment-diff" data-testid="amendment-diff-view">
		<div class="amendment-diff__legend" aria-hidden="false">
			<span class="amendment-diff__legend-item">
				<ins class="amendment-diff__added">{{ t('decidesk', 'Added text') }}</ins>
			</span>
			<span class="amendment-diff__legend-item">
				<del class="amendment-diff__removed">{{ t('decidesk', 'Removed text') }}</del>
			</span>
		</div>

		<p v-if="!segments.length" class="amendment-diff__empty" data-testid="amendment-diff-empty">
			{{ t('decidesk', 'There is no text to compare yet.') }}
		</p>

		<p v-else class="amendment-diff__body" data-testid="amendment-diff-body">
			<template v-for="(segment, index) in segments">
				<ins v-if="segment.type === 'added'"
					:key="`segment-${index}`"
					class="amendment-diff__added">{{ segment.text }}</ins>
				<del v-else-if="segment.type === 'removed'"
					:key="`segment-${index}`"
					class="amendment-diff__removed">{{ segment.text }}</del>
				<span v-else :key="`segment-${index}`">{{ segment.text }}</span>
				<template v-if="index < segments.length - 1">{{ ' ' }}</template>
			</template>
		</p>
	</div>
</template>

<script>
import { diffWords } from '../utils/textDiff.js'

export default {
	name: 'AmendmentDiffView',
	props: {
		/** The original (parent motion) text. */
		originalText: { type: String, default: '' },
		/** The amendment's proposed replacement text. */
		proposedText: { type: String, default: '' },
	},
	computed: {
		/** @spec openspec/specs/motion-amendment/spec.md */
		segments() {
			return diffWords(this.originalText, this.proposedText)
		},
	},
}
</script>

<style scoped>
.amendment-diff {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}
.amendment-diff__legend {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}
.amendment-diff__body {
	margin: 0;
	line-height: 1.6;
	white-space: pre-wrap;
}
.amendment-diff__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
.amendment-diff__added {
	background-color: var(--color-success-hover, var(--color-success));
	color: var(--color-main-text);
	text-decoration: underline;
	border-radius: var(--border-radius);
	padding: 0 2px;
}
.amendment-diff__removed {
	background-color: var(--color-error-hover, var(--color-error));
	color: var(--color-main-text);
	text-decoration: line-through;
	border-radius: var(--border-radius);
	padding: 0 2px;
}
</style>
