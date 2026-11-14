<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The accessibility status of one document: a badge with its text and colour
 (never colour alone), labelled as a heuristic check. Clicking it toggles the
 scan detail the parent shows.

 @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-002-per-document-accessibility-status-stored-and-badged
-->
<template>
	<component
		:is="interactive ? 'button' : 'span'"
		:type="interactive ? 'button' : undefined"
		class="decidiq-accessibility-badge"
		:class="`decidiq-accessibility-badge--${badge.status}`"
		:style="{ '--decidiq-badge-color': badge.color }"
		:aria-expanded="interactive ? (expanded ? 'true' : 'false') : undefined"
		:data-testid="`accessibility-badge-${badge.status}`"
		@click="interactive && $emit('toggle')">
		<span class="decidiq-accessibility-badge__dot" aria-hidden="true" />
		{{ badge.label }}
	</component>
</template>

<script>
import { statusBadge } from '../utils/documentAccessibility.js'

export default {
	name: 'AccessibilityBadge',

	props: {
		/** The report status: pass, warnings, fail or not-scanned. */
		status: { type: String, default: 'not-scanned' },
		/** Whether the scan detail is open. */
		expanded: { type: Boolean, default: false },
		/** Whether the badge opens the scan detail; false draws it as text only. */
		interactive: { type: Boolean, default: true },
	},

	emits: ['toggle'],

	computed: {
		/** The badge's text and colour. */
		badge() {
			return statusBadge(this.status)
		},
	},
}
</script>

<style scoped>
.decidiq-accessibility-badge {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: 0 calc(var(--default-grid-baseline) * 2);
	min-height: var(--default-clickable-area);
	border: 1px solid var(--decidiq-badge-color);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

button.decidiq-accessibility-badge {
	cursor: pointer;
}

.decidiq-accessibility-badge:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.decidiq-accessibility-badge__dot {
	width: calc(var(--default-grid-baseline) * 2);
	height: calc(var(--default-grid-baseline) * 2);
	border-radius: 50%;
	background: var(--decidiq-badge-color);
}
</style>
